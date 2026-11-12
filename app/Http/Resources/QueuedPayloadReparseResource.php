<?php

namespace App\Http\Resources;

use App\Models\RawScrapePayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Acknowledges that a raw payload was queued for parsing. Load the payload's scrapeSource first.
 *
 * @mixin RawScrapePayload
 */
class QueuedPayloadReparseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'raw_scrape_payload_id' => $this->id,
            'parser_engine' => $this->scrapeSource->parser_engine->value,
            'queued' => true,
        ];
    }
}
