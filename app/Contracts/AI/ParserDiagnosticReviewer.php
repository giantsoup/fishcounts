<?php

namespace App\Contracts\AI;

use App\DTOs\ParserDiagnosticReviewRequestData;
use App\DTOs\ParserDiagnosticReviewResultData;

interface ParserDiagnosticReviewer
{
    public function review(ParserDiagnosticReviewRequestData $request): ParserDiagnosticReviewResultData;
}
