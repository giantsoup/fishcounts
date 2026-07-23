<?php

namespace App\DTOs;

use App\Enums\ParserEngine;

final readonly class ParseRawPayloadOptions
{
    public function __construct(
        public bool $dispatchDeduplication = true,
        public bool $dispatchDiagnosticReviews = true,
        public ?int $parserDiagnosticReviewRunId = null,
        public ParserEngine $parserEngine = ParserEngine::Deterministic,
        public ?string $executionKey = null,
    ) {}

    public static function maintenance(): self
    {
        return new self(
            dispatchDeduplication: false,
            dispatchDiagnosticReviews: false,
            parserEngine: ParserEngine::Deterministic,
        );
    }
}
