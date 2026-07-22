<?php

namespace App\Actions\Parsing;

use App\DTOs\ParseRawPayloadOptions;
use App\DTOs\ParseRawPayloadResult;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\ParserReportOverrideApplier;
use App\Services\Parsing\RawPayloadEvaluator;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ParseRawPayloadAction
{
    public function __construct(
        private readonly RawPayloadEvaluator $evaluator,
        private readonly TripReportNormalizer $normalizer,
        private readonly ParsedReportValidator $validator,
        private readonly ParserReportOverrideApplier $overrideApplier,
    ) {}

    public function handle(
        int $rawScrapePayloadId,
        bool $shouldDispatchDeduplication = true,
        ?int $parserDiagnosticReviewRunId = null,
    ): ParseRawPayloadResult {
        return $this->handleWithOptions(
            $rawScrapePayloadId,
            new ParseRawPayloadOptions(
                dispatchDeduplication: $shouldDispatchDeduplication,
                parserDiagnosticReviewRunId: $parserDiagnosticReviewRunId,
            ),
        );
    }

    public function handleWithOptions(int $rawScrapePayloadId, ParseRawPayloadOptions $options): ParseRawPayloadResult
    {
        [$payload, $rawPayload, $parsed] = $this->evaluator->evaluate($rawScrapePayloadId);
        [$parsed, $parsedReportCount, $diagnosticCount] = DB::transaction(function () use ($payload, $rawPayload, $parsed): array {
            $lockedPayload = RawScrapePayload::query()->with('scrapeSource')->lockForUpdate()->findOrFail($payload->id);

            if (! hash_equals($payload->payload_hash, $lockedPayload->payload_hash)) {
                throw new RuntimeException('The raw payload changed while it was being parsed.');
            }

            $payload = $lockedPayload;
            $parsed = $this->overrideApplier->apply($payload, $rawPayload, $parsed);
            $diagnostics = $this->validator->validate($payload, $rawPayload, $parsed);
            $parsedReportCount = $this->normalizer->replaceForPayload($payload, $parsed, $diagnostics);
            $diagnosticCount = ParserError::query()
                ->where('raw_scrape_payload_id', $payload->id)
                ->whereNull('resolution_type')
                ->count();

            return [$parsed, $parsedReportCount, $diagnosticCount];
        }, attempts: 3);
        $parserVersion = $parsed->tripReports->first()?->metadata['parser'] ?? $parsed->parserVersion ?? 'unknown';

        if ($options->dispatchDeduplication) {
            DeduplicateTripReportsJob::dispatch($payload->target_date->toDateString())->afterCommit();
        }

        $reviewRun = $options->parserDiagnosticReviewRunId === null
            ? null
            : ParserDiagnosticReviewRun::query()
                ->whereKey($options->parserDiagnosticReviewRunId)
                ->where('raw_scrape_payload_id', $payload->id)
                ->first();

        if ($options->dispatchDiagnosticReviews && $diagnosticCount > 0 && (bool) config('fish.ai_review.dispatch_enabled')) {
            try {
                $reviewRun?->markQueued();
                DispatchParserDiagnosticReviewBatchesJob::dispatch($payload->id, $options->parserDiagnosticReviewRunId)->afterCommit();
            } catch (Throwable $throwable) {
                $reviewRun?->markFailed($throwable);
                report($throwable);
            }
        } elseif ($options->dispatchDiagnosticReviews && $reviewRun !== null && $diagnosticCount === 0) {
            $reviewRun->markCompleted();
        } elseif ($options->dispatchDiagnosticReviews && $reviewRun !== null) {
            $reviewRun->markFailed('AI review dispatch became unavailable after the payload was reparsed.');
        }

        return new ParseRawPayloadResult(
            rawScrapePayloadId: $payload->id,
            parserVersion: $parserVersion,
            parsedReportCount: $parsedReportCount,
            diagnosticCount: $diagnosticCount,
            shouldDispatchDeduplication: $options->dispatchDeduplication,
        );
    }
}
