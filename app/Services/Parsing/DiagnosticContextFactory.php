<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use Illuminate\Support\Str;

class DiagnosticContextFactory
{
    public function sanitizeDiagnosticText(string $text): string
    {
        return $this->sanitizeText($text);
    }

    public function paragraphForReport(RawPayloadData $payload, ParsedTripReportData $report, int $reportIndex = 0): string
    {
        $paragraphs = $this->fishCountParagraphs($payload);
        $rawCounts = $this->sanitizeText($report->rawFishCountText ?? '');
        $matchingParagraphs = collect($paragraphs)->filter(
            fn (string $paragraph): bool => $rawCounts !== '' && Str::contains($paragraph, $rawCounts, true),
        )->values();
        $matchingParagraph = $matchingParagraphs->get($reportIndex) ?? $matchingParagraphs->first();

        if (is_string($matchingParagraph)) {
            return $matchingParagraph;
        }

        return $this->sanitizeText(collect([
            $report->boatName,
            $report->tripTypeName,
            $report->anglers === null ? null : "{$report->anglers} anglers",
            $report->rawFishCountText,
        ])->filter()->implode(' | '));
    }

    /** @return array<int, string> */
    public function fishCountParagraphs(RawPayloadData $payload): array
    {
        $body = preg_replace('/<(script|style|head|nav|form)\b[^>]*>.*?<\/\1>/is', ' ', $payload->body) ?? '';
        $body = preg_replace('/<div\b(?=[^>]*(?:border-top\s*:\s*1px|\bclass=[\'\"][^\'\"]*\brow\b))[^>]*>/i', "\n", $body) ?? $body;
        $body = preg_replace('/<\/(?:p|li|tr|section|article|table|h[1-6])\s*>/i', "\n", $body) ?? $body;
        $body = preg_replace('/<\/(?:td|th|div)\s*>/i', ' | ', $body) ?? $body;
        $body = preg_replace('/<br\s*\/?\s*>/i', "\n", $body) ?? $body;

        return collect(preg_split('/\R+/', $body) ?: [])
            ->map(fn (string $paragraph): string => $this->sanitizeText($paragraph))
            ->filter(fn (string $paragraph): bool => $paragraph !== '')
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $evidence */
    public function context(ParsedReportValidationData $data, array $evidence): array
    {
        return [
            'source' => $this->sanitizeText($data->payload->sourceKey),
            'date' => $data->payload->targetDate->toDateString(),
            'url' => $this->sanitizeUrl($data->payload->url),
            'parser_version' => $this->sanitizeText($data->parserVersion),
            'format' => $this->sanitizeText($data->format),
            'report_index' => $data->reportIndex,
            'source_identifier' => $data->sourceIdentifier === null ? null : $this->sanitizeText($data->sourceIdentifier),
            'sanitized_paragraph' => $data->sanitizedParagraph,
            'extracted_fields' => $this->sanitizeContextValue($this->extractedFields($data->report)),
            'evidence' => $this->sanitizeContextValue($evidence),
        ];
    }

    private function sanitizeText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\b(authorization|proxy-authorization)\b\s*[:=]\s*(?:(?:bearer|basic|digest)\s+)?[^\s,;]+/iu', '$1=[redacted]', $text) ?? $text;
        $text = preg_replace('/\b(cookie|set-cookie)\b\s*[:=]\s*[^\r\n]*/iu', '$1=[redacted]', $text) ?? $text;
        $text = preg_replace('/\b(api[-_ ]?key|access[-_ ]?token|password)\b\s*[:=]\s*[^\s,;]+/iu', '$1=[redacted]', $text) ?? $text;

        return $this->limit(Str::of($text)
            ->replace("\u{00A0}", ' ')
            ->squish()
            ->toString());
    }

    private function limit(string $value): string
    {
        $maximumLength = max(1, (int) config('fish.parsing.diagnostics.max_paragraph_length', 2000));

        return Str::substr($value, 0, $maximumLength);
    }

    private function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return "{$parts['scheme']}://{$parts['host']}{$port}{$path}";
    }

    private function sanitizeContextValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->map(fn (mixed $item): mixed => $this->sanitizeContextValue($item))
            ->all();
    }

    /** @return array<string, mixed> */
    private function extractedFields(?ParsedTripReportData $report): array
    {
        if ($report === null) {
            return [];
        }

        return [
            'boat' => $report->boatName,
            'landing' => $report->landingName,
            'trip_type' => $report->tripTypeName,
            'anglers' => $report->anglers,
            'species_counts' => collect($report->speciesCounts)
                ->map(fn ($count): array => [
                    'species' => $count->speciesName,
                    'retained' => $count->count,
                    'released' => $count->releasedCount,
                ])
                ->values()
                ->all(),
        ];
    }
}
