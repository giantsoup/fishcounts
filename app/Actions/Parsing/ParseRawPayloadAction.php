<?php

namespace App\Actions\Parsing;

use App\DTOs\ParseRawPayloadResult;
use App\DTOs\RawPayloadData;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\TripReportNormalizer;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;

class ParseRawPayloadAction
{
    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly TripReportNormalizer $normalizer,
        private readonly ParsedReportValidator $validator,
    ) {}

    public function handle(int $rawScrapePayloadId, bool $shouldDispatchDeduplication = true): ParseRawPayloadResult
    {
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
        $diagnostics = $this->validator->validate($payload, $rawPayload, $parsed);
        $parsedReportCount = $this->normalizer->replaceForPayload($payload, $parsed, $diagnostics);
        $parserVersion = $parsed->tripReports->first()?->metadata['parser'] ?? $parsed->parserVersion ?? 'unknown';
        $diagnosticCount = ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->whereNull('resolution_type')
            ->count();

        if ($shouldDispatchDeduplication) {
            DeduplicateTripReportsJob::dispatch($payload->target_date->toDateString());
        }

        if ($diagnosticCount > 0 && (bool) config('fish.ai_review.dispatch_enabled')) {
            rescue(
                fn () => ReviewParserDiagnosticsJob::dispatch($payload->id)->afterCommit(),
                report: true,
            );
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
