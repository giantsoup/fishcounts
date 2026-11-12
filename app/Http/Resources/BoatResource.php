<?php

namespace App\Http\Resources;

use App\Models\Boat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Boat
 */
class BoatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'landing' => $this->whenLoaded('landing', fn (): ?array => $this->landing === null ? null : [
                'id' => $this->landing->id,
                'name' => $this->landing->name,
            ]),
            'booking_url' => $this->booking_url,
            'aliases' => AliasResource::collection($this->whenLoaded('aliases')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
