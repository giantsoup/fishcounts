<?php

namespace App\Models;

use App\Enums\CatchQuantityBasis;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class SpeciesCount extends Model
{
    protected $attributes = [
        'released_count' => 0,
        'is_retained_count' => true,
        'quantity_basis' => 'legacy_unclassified',
        'has_estimated_components' => false,
        'has_unquantified_components' => false,
    ];

    protected function casts(): array
    {
        return ['is_retained_count' => 'boolean', 'count' => 'integer', 'released_count' => 'integer', 'quantity_basis' => CatchQuantityBasis::class, 'components' => 'array', 'has_estimated_components' => 'boolean', 'has_unquantified_components' => 'boolean'];
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
