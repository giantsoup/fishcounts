<?php

namespace App\Services\IssueTracking;

use App\Enums\ParserDiagnosticReviewClassification;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Services\Parsing\DiagnosticContextFactory;
use Illuminate\Support\Str;
use JsonException;

final class ParserBugIssueRenderer
{
    public function __construct(private readonly DiagnosticContextFactory $contextFactory) {}

    public function title(ParserDiagnosticReview $review, string $sourceSlug, string $field): string
    {
        $description = match ($review->classification) {
            ParserDiagnosticReviewClassification::NewEntityCandidate => 'Unrecognized canonical entity',
            ParserDiagnosticReviewClassification::ParserBoundaryError => 'Incorrect parser boundary',
            ParserDiagnosticReviewClassification::FractionalTripConflict => 'Fractional trip parsed as a count',
            ParserDiagnosticReviewClassification::ValueExtractionError => 'Incorrect value extraction',
            ParserDiagnosticReviewClassification::MissingReport => 'Expected report was not parsed',
            default => 'Validated parser defect',
        };

        $title = "[Parser][{$sourceSlug}] {$description} for ".Str::of($field)->replace('_', ' ')->lower();

        return Str::substr($title, 0, (int) config('fish.github_issues.limits.max_title_length'));
    }

    /** @throws JsonException */
    public function body(
        ParserDiagnosticReview $review,
        ParserError $parserError,
        string $signature,
        string $sourceSlug,
    ): string {
        $context = is_array($parserError->context) ? $parserError->context : [];
        $parserVersion = $this->safeText((string) ($context['parser_version'] ?? $review->rawScrapePayload?->parser_version ?? 'unknown'));
        $sourceUrl = $this->safeUrl((string) ($context['url'] ?? ''));
        $paragraph = $this->safeText((string) ($context['sanitized_paragraph'] ?? ''));
        $actualParse = $this->json($context['extracted_fields'] ?? []);
        $expectedParse = $this->json($this->expectedParse($review, $parserError, $context));
        $evidence = $this->json($context['evidence'] ?? []);
        $field = $this->safeText($parserError->raw_field ?? 'report');
        $diagnosticType = $this->safeText($parserError->error_type);

        $body = view('github.parser-bug-issue', [
            'signature' => $signature,
            'sourceSlug' => $sourceSlug,
            'sourceUrl' => $sourceUrl,
            'parserVersion' => $parserVersion,
            'paragraph' => $paragraph,
            'actualParse' => $actualParse,
            'expectedParse' => $expectedParse,
            'evidence' => $evidence,
            'reviewId' => $review->id,
            'confidence' => number_format((float) $review->confidence, 4, '.', ''),
            'classification' => $review->classification?->value ?? 'unknown',
            'diagnosticType' => $diagnosticType,
            'field' => $field,
        ])->render();

        return Str::substr(trim($body), 0, (int) config('fish.github_issues.limits.max_body_length'));
    }

    private function safeText(string $value): string
    {
        $value = $this->contextFactory->sanitizeDiagnosticText($value);
        $value = preg_replace('/\bBearer\s+\S+/iu', 'Bearer [redacted]', $value) ?? $value;
        $value = str_replace('@', "@\u{200B}", $value);

        return Str::substr($value, 0, (int) config('fish.github_issues.limits.max_section_length'));
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return $this->safeText("{$parts['scheme']}://{$parts['host']}{$port}{$path}");
    }

    /** @throws JsonException */
    private function json(mixed $value): string
    {
        $sanitized = $this->sanitizeValue($value);
        $json = json_encode($sanitized, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Str::substr($json, 0, (int) config('fish.github_issues.limits.max_section_length'));
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->safeText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->take(100)
            ->map(fn (mixed $item): mixed => $this->sanitizeValue($item))
            ->all();
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function expectedParse(ParserDiagnosticReview $review, ParserError $parserError, array $context): array
    {
        $corrections = $review->validated_result['corrections'] ?? [];
        if ($corrections !== []) {
            return $corrections;
        }

        return match ($review->classification) {
            ParserDiagnosticReviewClassification::NewEntityCandidate => [
                'expected_outcome' => 'Recognize or explicitly surface the source entity after canonical review.',
                'field' => $parserError->raw_field ?? 'report',
                'raw_value' => $parserError->raw_value,
            ],
            ParserDiagnosticReviewClassification::MissingReport => [
                'expected_outcome' => 'Produce the report represented by the sanitized source paragraph.',
                'field' => $parserError->raw_field ?? 'report',
                'source_identifier' => $context['source_identifier'] ?? null,
            ],
            default => [],
        };
    }
}
