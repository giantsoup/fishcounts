<?php

namespace App\Models;

use App\Enums\EnvironmentalLocationType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class EnvironmentalPayload extends Model
{
    protected function casts(): array
    {
        return [
            'observed_date' => 'date',
            'location_type' => EnvironmentalLocationType::class,
            'fetched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<EnvironmentalSource, $this> */
    public function environmentalSource(): BelongsTo
    {
        return $this->belongsTo(EnvironmentalSource::class);
    }

    /** @return HasMany<EnvironmentalObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(EnvironmentalObservation::class);
    }
}
