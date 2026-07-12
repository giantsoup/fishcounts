<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class Species extends Model
{
    protected $attributes = [
        'environmental_location_profile' => 'san_diego_bight',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<SpeciesAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(SpeciesAlias::class);
    }
}
