<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class SpeciesAlias extends Model
{
    /** @return BelongsTo<Species, $this> */
    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }
}
