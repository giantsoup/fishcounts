<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class SpeciesCount extends Model
{
    protected $attributes = [
        'released_count' => 0,
        'is_retained_count' => true,
    ];

    protected function casts(): array
    {
        return ['is_retained_count' => 'boolean'];
    }

    /** @return BelongsTo<TripReport, $this> */
    public function tripReport(): BelongsTo
    {
        return $this->belongsTo(TripReport::class);
    }

    /** @return BelongsTo<Species, $this> */
    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }
}
