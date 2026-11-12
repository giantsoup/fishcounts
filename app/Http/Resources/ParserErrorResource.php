<?php

namespace App\Http\Resources;

use App\Models\ParserError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ParserError
 */
class ParserErrorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'error_type' => $this->error_type,
            'kind' => in_array($this->error_type, ParserError::ALIAS_ERROR_TYPES, true) ? 'alias' : 'structural',
            'raw_field' => $this->raw_field,
            'raw_value' => $this->raw_value,
            'message' => $this->message,
            'target_date' => $this->target_date?->toDateString(),
            'status' => $this->resolved_at === null && $this->resolution_type === null ? 'open' : 'resolved',
            'resolution_type' => $this->resolution_type?->value,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'raw_scrape_payload_id' => $this->raw_scrape_payload_id,
            'scrape_source' => ScrapeSourceResource::make($this->whenLoaded('scrapeSource')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
