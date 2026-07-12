<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;
use Illuminate\Support\Str;

class FractionalTripConflictRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report === null || ! preg_match('/\b(?<fraction>1\/2|3\/4)\s*Day\b/i', $data->sanitizedParagraph, $matches)) {
            return [];
        }

        $sourceTripType = Str::of($matches['fraction'].' Day')->lower()->squish()->toString();
        $parsedTripType = Str::of($data->report->tripTypeName ?? '')->lower()->squish()->toString();

        if (Str::startsWith($parsedTripType, $sourceTripType)) {
            return [];
        }

        return [new ParserDiagnosticFindingData(
            type: ParserDiagnosticType::FractionalTripConflict,
            field: 'trip_type',
            rawValue: $matches[0],
            message: "Source fraction [{$matches[0]}] conflicts with the extracted trip type.",
            evidence: ['source_trip_type' => $matches[0], 'extracted_trip_type' => $data->report->tripTypeName],
        )];
    }
}
