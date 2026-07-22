<?php

namespace App\Actions\Parsing;

use App\Models\RawScrapePayload;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\ParserDiagnosticSynchronizer;
use App\Services\Parsing\ParserReportOverrideApplier;
use App\Services\Parsing\RawPayloadEvaluator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RefreshRawPayloadDiagnostics
{
    public function __construct(
        private readonly RawPayloadEvaluator $evaluator,
        private readonly ParserReportOverrideApplier $overrideApplier,
        private readonly ParsedReportValidator $validator,
        private readonly ParserDiagnosticSynchronizer $diagnosticSynchronizer,
    ) {}

    public function handle(int $rawScrapePayloadId): int
    {
        [$payload, $rawPayload, $parsed] = $this->evaluator->evaluate($rawScrapePayloadId);

        return DB::transaction(function () use ($payload, $rawPayload, $parsed): int {
            $lockedPayload = RawScrapePayload::query()
                ->with('scrapeSource')
                ->lockForUpdate()
                ->findOrFail($payload->id);

            if (! hash_equals($payload->payload_hash, $lockedPayload->payload_hash)) {
                throw new RuntimeException('The raw payload changed while it was being parsed.');
            }

            $parsed = $this->overrideApplier->apply($lockedPayload, $rawPayload, $parsed);
            $diagnostics = $this->validator->validate($lockedPayload, $rawPayload, $parsed);

            return $this->diagnosticSynchronizer->sync($lockedPayload, $diagnostics);
        }, attempts: 3);
    }
}
