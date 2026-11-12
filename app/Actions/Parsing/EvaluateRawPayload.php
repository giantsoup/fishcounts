<?php

namespace App\Actions\Parsing;

use App\DTOs\RawPayloadEvaluationData;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\RawPayloadEvaluator;

/**
 * Dry-run deterministic parse of a stored payload: the reports the parser would produce
 * and the diagnostics the validator would raise for them. Writes nothing and never calls
 * the AI parser.
 */
class EvaluateRawPayload
{
    public function __construct(
        private readonly RawPayloadEvaluator $evaluator,
        private readonly ParsedReportValidator $validator,
    ) {}

    public function handle(int $rawScrapePayloadId): RawPayloadEvaluationData
    {
        [$payload, $rawPayload, $parsed] = $this->evaluator->evaluate($rawScrapePayloadId);

        return new RawPayloadEvaluationData(
            payload: $payload,
            parsed: $parsed,
            diagnostics: $this->validator->validate($payload, $rawPayload, $parsed),
        );
    }
}
