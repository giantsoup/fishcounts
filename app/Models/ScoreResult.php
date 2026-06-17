<?php

namespace App\Models;

use App\Enums\ScoreLevel;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class ScoreResult extends Model
{
    protected function casts(): array
    {
        return [
            'score_date' => 'date',
            'level' => ScoreLevel::class,
            'count_per_angler' => 'decimal:2',
            'explanation' => 'array',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }
}
