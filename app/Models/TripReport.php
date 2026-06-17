<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class TripReport extends Model
{
    protected $attributes = [
        'is_deduped_primary' => true,
        'source_confidence' => 100,
    ];

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'is_deduped_primary' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ScrapeSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class, 'source_id');
    }

    /** @return HasMany<SpeciesCount, $this> */
    public function speciesCounts(): HasMany
    {
        return $this->hasMany(SpeciesCount::class);
    }
}
