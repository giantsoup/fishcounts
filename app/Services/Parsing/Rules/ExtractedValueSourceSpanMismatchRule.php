<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;
use App\Services\Parsing\SourceFishCountGrammar;

class ExtractedValueSourceSpanMismatchRule implements ParsedReportDiagnosticRule
{
    public function __construct(private readonly SourceFishCountGrammar $sourceGrammar) {}

    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report === null || $data->sanitizedParagraph === '') {
            return [];
        }

        $sourceText = $this->sourceText($data);
        $findings = [];

        if ($data->report->anglers !== null && preg_match_all('/(?<count>\d+)\s+(?:anglers?|people|passengers?)\b/i', $sourceText, $matches)) {
            $sourceAnglers = array_map('intval', $matches['count']);

            if (! in_array($data->report->anglers, $sourceAnglers, true)) {
                $findings[] = new ParserDiagnosticFindingData(
                    type: ParserDiagnosticType::ExtractedValueSourceSpanMismatch,
                    field: 'anglers',
                    rawValue: (string) $data->report->anglers,
                    message: 'Extracted angler count does not match an angler-labelled source span.',
                    evidence: ['extracted' => $data->report->anglers, 'source_values' => $sourceAnglers],
                );
            }
        }

        $speciesMismatches = [];

        foreach ($data->report->speciesCounts as $speciesCount) {
            $sentenceReleasedPattern = '/(?<retained>\d+)\s+'.preg_quote($speciesCount->speciesName, '/').'\.\s*(?<released>\d+)\s+were\s+released\b/iu';
            preg_match_all($sentenceReleasedPattern, $sourceText, $sentenceReleasedMatches, PREG_SET_ORDER);
            $remainingSourceText = preg_replace($sentenceReleasedPattern, ' ', $sourceText) ?? $sourceText;
            $limitsPattern = '/\blimits\s*\(\s*(?<retained>\d+)\s*\)\s+of\s+'.preg_quote($speciesCount->speciesName, '/').'\b/iu';
            preg_match_all($limitsPattern, $remainingSourceText, $limitsMatches, PREG_SET_ORDER);
            $remainingSourceText = preg_replace($limitsPattern, ' ', $remainingSourceText) ?? $remainingSourceText;
            $parentheticalPattern = '/(?<retained>\d+)\s+'.preg_quote($speciesCount->speciesName, '/').'\s*,?\s*\(\s*(?<released>\d+)\s+released\s*\)/iu';
            preg_match_all($parentheticalPattern, $remainingSourceText, $parentheticalMatches, PREG_SET_ORDER);
            $remainingSourceText = preg_replace($parentheticalPattern, ' ', $remainingSourceText) ?? $remainingSourceText;
            $releasedOnlyPattern = '/(?<released>\d+)\s+'.preg_quote($speciesCount->speciesName, '/').'\s*\(\s*released\s*\)/iu';
            preg_match_all($releasedOnlyPattern, $remainingSourceText, $releasedOnlyMatches, PREG_SET_ORDER);
            $remainingSourceText = preg_replace($releasedOnlyPattern, ' ', $remainingSourceText) ?? $remainingSourceText;
            $pattern = '/(?<count>\d+)\s+'.preg_quote($speciesCount->speciesName, '/').'(?<released>\s+Released)?\b/iu';
            preg_match_all($pattern, $remainingSourceText, $matches, PREG_SET_ORDER);
            $retained = array_sum(array_map(fn (array $match): int => (int) $match['retained'], $sentenceReleasedMatches));
            $retained += array_sum(array_map(fn (array $match): int => (int) $match['retained'], $limitsMatches));
            $retained += array_sum(array_map(fn (array $match): int => (int) $match['retained'], $parentheticalMatches));
            $released = array_sum(array_map(fn (array $match): int => (int) $match['released'], $sentenceReleasedMatches));
            $released += array_sum(array_map(fn (array $match): int => (int) $match['released'], $parentheticalMatches));
            $released += array_sum(array_map(fn (array $match): int => (int) $match['released'], $releasedOnlyMatches));

            foreach ($matches as $match) {
                if (($match['released'] ?? '') !== '') {
                    $released += (int) $match['count'];
                } else {
                    $retained += (int) $match['count'];
                }
            }

            if (($matches === [] && $sentenceReleasedMatches === [] && $limitsMatches === [] && $parentheticalMatches === [] && $releasedOnlyMatches === []) || $retained !== $speciesCount->count || $released !== $speciesCount->releasedCount) {
                $speciesMismatches[] = [
                    'species' => $speciesCount->speciesName,
                    'extracted_retained' => $speciesCount->count,
                    'extracted_released' => $speciesCount->releasedCount,
                    'source_retained' => $retained,
                    'source_released' => $released,
                ];
            }
        }

        if ($speciesMismatches !== []) {
            $findings[] = new ParserDiagnosticFindingData(
                type: ParserDiagnosticType::ExtractedValueSourceSpanMismatch,
                field: 'species_counts',
                rawValue: $data->report->rawFishCountText,
                message: 'Extracted retained or released counts do not match their source spans.',
                evidence: ['mismatches' => $speciesMismatches],
            );
        }

        return $findings;
    }

    private function sourceText(ParsedReportValidationData $data): string
    {
        $sourceText = $data->format === 'narrative-list-item' && $data->report?->rawFishCountText !== null
            ? $data->report->rawFishCountText
            : $data->sanitizedParagraph;

        $sourceText = preg_replace('/\bCakico\s+Bass\b/i', 'Calico Bass', $sourceText) ?? $sourceText;

        return $this->sourceGrammar->normalize($sourceText);
    }
}
