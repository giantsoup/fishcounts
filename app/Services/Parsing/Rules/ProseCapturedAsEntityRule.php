<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;

class ProseCapturedAsEntityRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report === null) {
            return [];
        }

        $entities = array_merge(
            ['boat' => $data->report->boatName, 'landing' => $data->report->landingName],
            collect($data->report->speciesCounts)
                ->mapWithKeys(fn ($count, int $index): array => ["species:{$index}" => $count->speciesName])
                ->all(),
        );
        $findings = [];

        foreach ($entities as $field => $value) {
            if (! is_string($value) || ! $this->looksLikeProse($value)) {
                continue;
            }

            $normalizedField = str_starts_with($field, 'species:') ? 'species' : $field;
            $findings[] = new ParserDiagnosticFindingData(
                type: ParserDiagnosticType::ProseCapturedAsEntity,
                field: $normalizedField,
                rawValue: $value,
                message: "Extracted {$normalizedField} [{$value}] resembles narrative prose.",
                evidence: ['matched_prose_pattern' => true],
            );
        }

        return $findings;
    }

    private function looksLikeProse(string $value): bool
    {
        return preg_match('/\b(?:returned|finished|caught|called|checked|ended|landed|with|trip|charter|today|yesterday|last night)\b/i', $value) === 1
            || preg_match('/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:the)?\s*[A-Z]/i', $value) === 1;
    }
}
