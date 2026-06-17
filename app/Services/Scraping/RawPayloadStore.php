<?php

namespace App\Services\Scraping;

use App\DTOs\FetchResult;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;

class RawPayloadStore
{
    public function store(ScrapeRun $scrapeRun, ScrapeSource $source, FetchResult $result): RawScrapePayload
    {
        return RawScrapePayload::query()->firstOrCreate(
            [
                'scrape_source_id' => $source->id,
                'target_date' => $scrapeRun->target_date,
                'payload_hash' => hash('sha256', $result->body),
            ],
            [
                'scrape_run_id' => $scrapeRun->id,
                'url' => $result->url,
                'http_status' => $result->statusCode,
                'content_type' => $result->contentType,
                'payload' => $result->body,
                'fetched_at' => $result->fetchedAt,
                'metadata' => $result->metadata,
            ],
        );
    }
}
