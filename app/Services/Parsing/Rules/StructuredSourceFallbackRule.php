<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;

class StructuredSourceFallbackRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        $structuredSourceKeys = config('fish.parsing.diagnostics.structured_source_keys', []);

        if (
            $data->report === null
            || ! is_array($structuredSourceKeys)
            || ! in_array($data->payload->sourceKey, $structuredSourceKeys, true)
            || ! isset($data->report->metadata['fallback_parser'])
        ) {
            return [];
        }

        return [new ParserDiagnosticFindingData(
            type: ParserDiagnosticType::StructuredSourceFallback,
            field: 'parser',
            rawValue: (string) $data->report->metadata['fallback_parser'],
            message: 'A structured source required its generic parser fallback.',
            evidence: [
                'primary_parser' => $data->parserVersion,
                'fallback_parser' => (string) $data->report->metadata['fallback_parser'],
            ],
        )];
    }
}
