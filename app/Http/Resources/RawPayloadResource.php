<?php

namespace App\Http\Resources;

use App\Models\RawScrapePayload;
use App\Services\Parsing\DiagnosticContextFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Raw payload metadata. The body is served separately by RawPayloadBodyResource.
 *
 * @mixin RawScrapePayload
 */
class RawPayloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scrape_source' => ScrapeSourceResource::make($this->whenLoaded('scrapeSource')),
            'target_date' => $this->target_date?->toDateString(),
            'url' => $this->url,
            'http_status' => $this->http_status,
            'content_type' => $this->content_type,
            'payload_hash' => $this->payload_hash,
            'body_bytes' => strlen((string) $this->payload),
            'fetched_at' => $this->fetched_at?->toIso8601String(),
            'parsed_at' => $this->parsed_at?->toIso8601String(),
            'parser_version' => $this->parser_version,
            'error_message' => $this->error_message === null
                ? null
                : app(DiagnosticContextFactory::class)->redactSecrets($this->error_message),
            'authoritative_parser_execution' => ParserExecutionResource::make($this->whenLoaded('authoritativeParserExecution')),
        ];
    }
}
