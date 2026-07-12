<?php

namespace App\Contracts\Parsing;

use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;

interface ParsedReportDiagnosticRule
{
    /** @return array<int, ParserDiagnosticFindingData> */
    public function inspect(ParsedReportValidationData $data): array;
}
