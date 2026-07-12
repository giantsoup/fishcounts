<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;
use Illuminate\Support\Str;

class ExcessiveNameLengthRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report === null) {
            return [];
        }

        $maximumLength = max(1, (int) config('fish.parsing.diagnostics.max_entity_name_length', 60));
        $entities = [
            'boat' => $data->report->boatName,
            'landing' => $data->report->landingName,
            'trip_type' => $data->report->tripTypeName,
        ];

        foreach ($data->report->speciesCounts as $index => $speciesCount) {
            $entities["species:{$index}"] = $speciesCount->speciesName;
        }

        $findings = [];

        foreach ($entities as $field => $value) {
            if (! is_string($value) || Str::length($value) <= $maximumLength) {
                continue;
            }

            $normalizedField = str_starts_with($field, 'species:') ? 'species' : $field;
            $findings[] = new ParserDiagnosticFindingData(
                type: ParserDiagnosticType::ExcessiveNameLength,
                field: $normalizedField,
                rawValue: $value,
                message: "Extracted {$normalizedField} exceeds {$maximumLength} characters.",
                evidence: ['length' => Str::length($value), 'maximum_length' => $maximumLength],
            );
        }

        return $findings;
    }
}
