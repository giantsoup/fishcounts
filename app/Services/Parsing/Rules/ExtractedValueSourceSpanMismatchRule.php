<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;

class ExtractedValueSourceSpanMismatchRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report === null || $data->sanitizedParagraph === '') {
            return [];
        }

        $findings = [];

        if ($data->report->anglers !== null && preg_match_all('/(?<count>\d+)\s+(?:anglers?|people|passengers?)\b/i', $data->sanitizedParagraph, $matches)) {
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
            $pattern = '/(?<count>\d+)\s+'.preg_quote($speciesCount->speciesName, '/').'(?<released>\s+Released)?\b/iu';
            preg_match_all($pattern, $data->sanitizedParagraph, $matches, PREG_SET_ORDER);
            $retained = 0;
            $released = 0;

            foreach ($matches as $match) {
                if (($match['released'] ?? '') !== '') {
                    $released += (int) $match['count'];
                } else {
                    $retained += (int) $match['count'];
                }
            }

            if ($matches === [] || $retained !== $speciesCount->count || $released !== $speciesCount->releasedCount) {
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
}
