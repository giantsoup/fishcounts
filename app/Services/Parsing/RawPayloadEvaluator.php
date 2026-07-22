<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\RawPayloadData;
use App\Models\RawScrapePayload;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;

class RawPayloadEvaluator
{
    public function __construct(private readonly SourceAdapterRegistry $registry) {}

    /** @return array{RawScrapePayload, RawPayloadData, ParsedFishCountCollection} */
    public function evaluate(int $rawScrapePayloadId): array
    {
        $payload = RawScrapePayload::query()
            ->with('scrapeSource')
            ->findOrFail($rawScrapePayloadId);
        $rawPayload = new RawPayloadData(
            sourceKey: $payload->scrapeSource->slug,
            targetDate: CarbonImmutable::parse($payload->target_date),
            url: $payload->url,
            body: $payload->payload,
            metadata: $payload->metadata ?? [],
        );
        $parsed = $this->registry->forSource($payload->scrapeSource)->parse($rawPayload);

        return [$payload, $rawPayload, $parsed];
    }
}
