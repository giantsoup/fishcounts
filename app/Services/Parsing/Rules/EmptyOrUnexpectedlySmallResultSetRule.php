<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;

class EmptyOrUnexpectedlySmallResultSetRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report !== null || ($data->sourceEvidence['has_missing_report'] ?? false) !== true) {
            return [];
        }

        $candidateLabel = $data->sourceEvidence['candidate_label'] ?? null;

        return [new ParserDiagnosticFindingData(
            type: ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet,
            field: 'report',
            rawValue: is_string($candidateLabel) ? $candidateLabel : null,
            message: 'Source-specific report evidence was not represented in the parsed result set.',
            evidence: $data->sourceEvidence,
        )];
    }
}
