<?php

namespace App\Services\AI;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\DTOs\ParserDiagnosticReviewProviderResponseData;
use LogicException;

final class DisabledParserDiagnosticReviewer implements ParserDiagnosticReviewer
{
    public function review(array $requests): ParserDiagnosticReviewProviderResponseData
    {
        throw new LogicException('AI parser diagnostic review is disabled.');
    }
}
