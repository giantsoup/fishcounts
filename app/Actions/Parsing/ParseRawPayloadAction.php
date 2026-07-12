<?php

namespace App\Actions\Parsing;

use App\DTOs\ParseRawPayloadResult;
use App\DTOs\RawPayloadData;
use App\Jobs\DeduplicateTripReportsJob;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Services\Parsing\TripReportNormalizer;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;

class ParseRawPayloadAction
{
    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly TripReportNormalizer $normalizer,
    ) {}

    public function handle(int $rawScrapePayloadId, bool $shouldDispatchDeduplication = true): ParseRawPayloadResult
    {
        $payload = RawScrapePayload::query()
            ->with('scrapeSource')
            ->findOrFail($rawScrapePayloadId);
        $adapter = $this->registry->forSource($payload->scrapeSource);
        $parsed = $adapter->parse(new RawPayloadData(
            sourceKey: $payload->scrapeSource->slug,
            targetDate: CarbonImmutable::parse($payload->target_date),
            url: $payload->url,
            body: $payload->payload,
            metadata: $payload->metadata ?? [],
        ));
        $parsedReportCount = $this->normalizer->replaceForPayload($payload, $parsed);
        $parserVersion = $parsed->tripReports->first()?->metadata['parser'] ?? 'unknown';
        $diagnosticCount = ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->whereNull('resolution_type')
            ->count();

        if ($shouldDispatchDeduplication) {
            DeduplicateTripReportsJob::dispatch($payload->target_date->toDateString());
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
