<?php

namespace App\Jobs;

use App\DTOs\RawPayloadData;
use App\Models\RawScrapePayload;
use App\Services\Parsing\TripReportNormalizer;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class ParseRawPayloadJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $rawScrapePayloadId)
    {
        $this->onQueue('parsing');
    }

    public function uniqueId(): string
    {
        return (string) $this->rawScrapePayloadId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SourceAdapterRegistry $registry, TripReportNormalizer $normalizer): void
    {
        $payload = RawScrapePayload::query()->with('scrapeSource')->findOrFail($this->rawScrapePayloadId);
        $adapter = $registry->forSource($payload->scrapeSource);

        $parsed = $adapter->parse(new RawPayloadData(
            sourceKey: $payload->scrapeSource->slug,
            targetDate: CarbonImmutable::parse($payload->target_date),
            url: $payload->url,
            body: $payload->payload,
            metadata: $payload->metadata ?? [],
        ));

        $normalizer->replaceForPayload($payload, $parsed);

        DeduplicateTripReportsJob::dispatch($payload->target_date->toDateString());
    }

    public function failed(Throwable $throwable): void
    {
        RawScrapePayload::query()
            ->whereKey($this->rawScrapePayloadId)
            ->update(['error_message' => str($throwable->getMessage())->limit(1000)->toString()]);
    }
}
