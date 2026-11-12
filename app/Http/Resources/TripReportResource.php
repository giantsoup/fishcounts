<?php

namespace App\Http\Resources;

use App\Models\TripReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trip report as the parser produced it. Boat, landing, and trip type are
 * embedded with only their id and name.
 *
 * @mixin TripReport
 */
class TripReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_date' => $this->trip_date?->toDateString(),
            'source_id' => $this->source_id,
            'raw_scrape_payload_id' => $this->raw_scrape_payload_id,
            'boat' => $this->whenLoaded('boat', fn (): ?array => $this->boat === null ? null : ['id' => $this->boat->id, 'name' => $this->boat->name]),
            'landing' => $this->whenLoaded('landing', fn (): ?array => $this->landing === null ? null : ['id' => $this->landing->id, 'name' => $this->landing->name]),
            'trip_type' => $this->whenLoaded('tripType', fn (): ?array => $this->tripType === null ? null : ['id' => $this->tripType->id, 'name' => $this->tripType->name]),
            'anglers' => $this->anglers,
            'raw_boat_name' => $this->raw_boat_name,
            'raw_landing_name' => $this->raw_landing_name,
            'raw_trip_type' => $this->raw_trip_type,
            'raw_fish_count_text' => $this->raw_fish_count_text,
            'is_deduped_primary' => $this->is_deduped_primary,
            'species_counts' => SpeciesCountResource::collection($this->whenLoaded('speciesCounts')),
        ];
    }
}
