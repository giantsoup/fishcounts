<?php

namespace App\Models;

use App\Enums\EnvironmentalLocationType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class EnvironmentalObservation extends Model
{
    protected function casts(): array
    {
        return [
            'observed_date' => 'date',
            'location_type' => EnvironmentalLocationType::class,
            'observed_at' => 'datetime',
            'value' => 'decimal:3',
            'quality_flags' => 'array',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<EnvironmentalSource, $this> */
    public function environmentalSource(): BelongsTo
    {
        return $this->belongsTo(EnvironmentalSource::class);
    }

    /** @return BelongsTo<EnvironmentalPayload, $this> */
    public function environmentalPayload(): BelongsTo
    {
        return $this->belongsTo(EnvironmentalPayload::class);
    }
}
