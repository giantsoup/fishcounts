<?php

namespace App\Contracts\AI;

use App\DTOs\ParserDiagnosticReviewProviderResponseData;
use App\DTOs\ParserDiagnosticReviewRequestData;

interface ParserDiagnosticReviewer
{
    /**
     * @param  non-empty-list<ParserDiagnosticReviewRequestData>  $requests
     */
    public function review(array $requests): ParserDiagnosticReviewProviderResponseData;
}
