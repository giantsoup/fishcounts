<?php

namespace App\Exceptions;

use App\Enums\ParserDiagnosticReviewStatus;
use Exception;

class InvalidParserDiagnosticReviewTransition extends Exception
{
    public function __construct(ParserDiagnosticReviewStatus $from, ParserDiagnosticReviewStatus $to)
    {
        parent::__construct("Parser diagnostic review cannot transition from {$from->value} to {$to->value}.");
    }
}
