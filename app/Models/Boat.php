<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class Boat extends Model
{
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Landing, $this> */
    public function landing(): BelongsTo
    {
        return $this->belongsTo(Landing::class);
    }

    /** @return HasMany<BoatAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(BoatAlias::class);
    }

    /** @return HasMany<TripReport, $this> */
    public function tripReports(): HasMany
    {
        return $this->hasMany(TripReport::class);
    }
}
