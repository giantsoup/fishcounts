<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class ScoreRun extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<ScoreResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ScoreResult::class);
    }
}
