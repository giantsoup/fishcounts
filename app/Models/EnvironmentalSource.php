<?php

namespace App\Models;

use App\Enums\EnvironmentalLocationType;
use App\Enums\EnvironmentalSourceType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class EnvironmentalSource extends Model
{
    protected $attributes = [
        'location_type' => 'local',
        'priority' => 100,
        'is_enabled' => true,
        'supports_historical_dates' => false,
        'rate_limit_seconds' => 10,
    ];

    protected function casts(): array
    {
        return [
            'source_type' => EnvironmentalSourceType::class,
            'location_type' => EnvironmentalLocationType::class,
            'is_enabled' => 'boolean',
            'supports_historical_dates' => 'boolean',
            'metadata' => 'array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /** @return HasMany<EnvironmentalPayload, $this> */
    public function payloads(): HasMany
    {
        return $this->hasMany(EnvironmentalPayload::class);
    }

    /** @return HasMany<EnvironmentalObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(EnvironmentalObservation::class);
    }
}
