<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class TripType extends Model
{
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<TripTypeAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(TripTypeAlias::class);
    }

    /** @param  Builder<TripType>  $query */
    #[Scope]
    protected function orderedForDisplay(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
