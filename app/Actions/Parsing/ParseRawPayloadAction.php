<?php

namespace App\Actions\Parsing;

use App\DTOs\ParseRawPayloadResult;
use App\DTOs\RawPayloadData;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\ParserReportOverrideApplier;
use App\Services\Parsing\TripReportNormalizer;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ParseRawPayloadAction
{
    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly TripReportNormalizer $normalizer,
        private readonly ParsedReportValidator $validator,
        private readonly ParserReportOverrideApplier $overrideApplier,
    ) {}

    public function handle(
        int $rawScrapePayloadId,
        bool $shouldDispatchDeduplication = true,
        ?int $parserDiagnosticReviewRunId = null,
    ): ParseRawPayloadResult {
        $payload = RawScrapePayload::query()
            ->with('scrapeSource')
            ->findOrFail($rawScrapePayloadId);
        $adapter = $this->registry->forSource($payload->scrapeSource);
        $rawPayload = new RawPayloadData(
            sourceKey: $payload->scrapeSource->slug,
            targetDate: CarbonImmutable::parse($payload->target_date),
            url: $payload->url,
            body: $payload->payload,
            metadata: $payload->metadata ?? [],
        );
        $parsed = $adapter->parse($rawPayload);
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

        if ($shouldDispatchDeduplication) {
            DeduplicateTripReportsJob::dispatch($payload->target_date->toDateString());
        }

        $reviewRun = $parserDiagnosticReviewRunId === null
            ? null
            : ParserDiagnosticReviewRun::query()
                ->whereKey($parserDiagnosticReviewRunId)
                ->where('raw_scrape_payload_id', $payload->id)
                ->first();

        if ($diagnosticCount > 0 && (bool) config('fish.ai_review.dispatch_enabled')) {
            try {
                $reviewRun?->markQueued();
                ReviewParserDiagnosticsJob::dispatch($payload->id, $parserDiagnosticReviewRunId)->afterCommit();
            } catch (Throwable $throwable) {
                $reviewRun?->markFailed($throwable);
                report($throwable);
            }
        } elseif ($reviewRun !== null && $diagnosticCount === 0) {
            $reviewRun->markCompleted();
        } elseif ($reviewRun !== null) {
            $reviewRun->markFailed('AI review dispatch became unavailable after the payload was reparsed.');
        }

        return new ParseRawPayloadResult(
            rawScrapePayloadId: $payload->id,
            parserVersion: $parserVersion,
            parsedReportCount: $parsedReportCount,
            diagnosticCount: $diagnosticCount,
            shouldDispatchDeduplication: $shouldDispatchDeduplication,
        );
    }
}
