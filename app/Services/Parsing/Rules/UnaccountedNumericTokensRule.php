<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;

class UnaccountedNumericTokensRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report === null || $data->sanitizedParagraph === '') {
            return [];
        }

        $remaining = $this->sourceText($data);
        $remaining = preg_replace([
            '/\b\d{4}-\d{2}-\d{2}\b/',
            '/\([^)]*(?:lbs?|pounds?)\b[^)]*\)/i',
            '/\b\d+(?:\.\d+|\/\d+)?\s*(?:day|hour)s?\b/i',
            '/\bday\s+\d+\b/i',
            '/\b\d+(?:\.\d+)?\s*(?:lb|lbs|pound|pounds|oz)\b/i',
            '/\b\d+(?:\s+(?:anglers?|people|passengers?|boats?|trips?)|[\s-]+packs?)\b/i',
        ], ' ', $remaining) ?? $remaining;

        foreach ([$data->report->boatName, $data->report->landingName, $data->report->tripTypeName] as $value) {
            if (is_string($value) && $value !== '') {
                $remaining = preg_replace('/'.preg_quote($value, '/').'/iu', ' ', $remaining) ?? $remaining;
            }
        }

        if ($data->format === 'structured-table' && $data->report->anglers !== null) {
            $remaining = preg_replace(
                '/\|\s*'.preg_quote((string) $data->report->anglers, '/').'\s*\|/u',
                '| |',
                $remaining,
                1,
            ) ?? $remaining;
        }

        foreach ($data->report->speciesCounts as $speciesCount) {
            $remaining = preg_replace(
                '/\b\d+\s+'.preg_quote($speciesCount->speciesName, '/').'\.\s*\d+\s+were\s+released\b/iu',
                ' ',
                $remaining,
            ) ?? $remaining;
            $remaining = preg_replace(
                '/\blimits\s*\(\s*'.preg_quote((string) $speciesCount->count, '/').'\s*\)\s+of\s+'.preg_quote($speciesCount->speciesName, '/').'\b/iu',
                ' ',
                $remaining,
            ) ?? $remaining;
            $remaining = preg_replace(
                '/\b\d+\s+'.preg_quote($speciesCount->speciesName, '/').'\s*,?\s*\(\s*\d+\s+released\s*\)/iu',
                ' ',
                $remaining,
            ) ?? $remaining;
            $remaining = preg_replace(
                '/\b\d+\s+'.preg_quote($speciesCount->speciesName, '/').'\s*\(\s*released\s*\)/iu',
                ' ',
                $remaining,
            ) ?? $remaining;
            $remaining = preg_replace(
                '/\b\d+\s+'.preg_quote($speciesCount->speciesName, '/').'(?:\s+Released)?\b/iu',
                ' ',
                $remaining,
            ) ?? $remaining;

            if ($speciesCount->rawText !== null && $speciesCount->rawText !== '') {
                $remaining = preg_replace('/'.preg_quote($speciesCount->rawText, '/').'/iu', ' ', $remaining) ?? $remaining;
            }
        }

        preg_match_all('/(?<![\/.])\b\d+\b(?![\/.])/', $remaining, $matches);
        $tokens = array_values(array_unique($matches[0] ?? []));

        if ($tokens === []) {
            return [];
        }

        return [new ParserDiagnosticFindingData(
            type: ParserDiagnosticType::UnaccountedNumericTokens,
            field: 'report',
            rawValue: implode(', ', $tokens),
            message: 'Source paragraph contains numeric tokens not represented by extracted fields.',
            evidence: ['unaccounted_tokens' => $tokens],
        )];
    }

    private function sourceText(ParsedReportValidationData $data): string
    {
        $sourceText = $data->format === 'narrative-list-item' && $data->report?->rawFishCountText !== null
            ? $data->report->rawFishCountText
            : $data->sanitizedParagraph;

        return preg_replace('/\bCakico\s+Bass\b/i', 'Calico Bass', $sourceText) ?? $sourceText;
    }
}
