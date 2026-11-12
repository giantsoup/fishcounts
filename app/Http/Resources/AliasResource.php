<?php

namespace App\Http\Resources;

use App\Models\BoatAlias;
use App\Models\SpeciesAlias;
use App\Models\TripTypeAlias;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One alias of a boat, species, or trip type.
 *
 * @mixin BoatAlias|SpeciesAlias|TripTypeAlias
 */
class AliasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'normalized_alias' => $this->normalized_alias,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
