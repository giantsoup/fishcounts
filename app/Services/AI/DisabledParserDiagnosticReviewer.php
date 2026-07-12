<?php

namespace App\Services\AI;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\DTOs\ParserDiagnosticReviewRequestData;
use App\DTOs\ParserDiagnosticReviewResultData;
use LogicException;

final class DisabledParserDiagnosticReviewer implements ParserDiagnosticReviewer
{
    public function review(ParserDiagnosticReviewRequestData $request): ParserDiagnosticReviewResultData
    {
        throw new LogicException('AI parser diagnostic review is disabled.');
    }
}
