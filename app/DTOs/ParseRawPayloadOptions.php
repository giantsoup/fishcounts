<?php

namespace App\DTOs;

final readonly class ParseRawPayloadOptions
{
    public function __construct(
        public bool $dispatchDeduplication = true,
        public bool $dispatchDiagnosticReviews = true,
        public ?int $parserDiagnosticReviewRunId = null,
    ) {}

    public static function maintenance(): self
    {
        return new self(
            dispatchDeduplication: false,
            dispatchDiagnosticReviews: false,
        );
    }
}
