<?php

namespace App\Http\Resources;

use App\Models\SpeciesCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One species line of a trip report. The species is embedded with only its id and name.
 *
 * @mixin SpeciesCount
 */
class SpeciesCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'species' => $this->whenLoaded('species', fn (): ?array => $this->species === null ? null : ['id' => $this->species->id, 'name' => $this->species->name]),
            'count' => $this->count,
            'released_count' => $this->released_count,
            'is_retained_count' => $this->is_retained_count,
            'quantity_basis' => $this->quantity_basis?->value,
            'raw_species_name' => $this->raw_species_name,
            'raw_count_text' => $this->raw_count_text,
        ];
    }
}
