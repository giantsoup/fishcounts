<?php

namespace App\Exceptions;

use RuntimeException;

class AiParserRateLimitExceededException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct('AI primary parsing rate-limit capacity is exhausted.');
    }
}
