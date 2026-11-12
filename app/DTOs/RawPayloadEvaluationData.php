<?php

namespace App\DTOs;

use App\Models\RawScrapePayload;

final readonly class RawPayloadEvaluationData
{
    /** @param array<int, ParserDiagnosticData> $diagnostics */
    public function __construct(
        public RawScrapePayload $payload,
        public ParsedFishCountCollection $parsed,
        public array $diagnostics,
    ) {}
}
