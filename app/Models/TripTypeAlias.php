<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class TripTypeAlias extends Model
{
    /** @return BelongsTo<TripType, $this> */
    public function tripType(): BelongsTo
    {
        return $this->belongsTo(TripType::class);
    }
}
