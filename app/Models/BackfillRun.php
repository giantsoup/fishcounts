<?php

namespace App\Models;

use App\Enums\BackfillRunStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class BackfillRun extends Model
{
    protected $attributes = [
        'status' => BackfillRunStatus::Pending->value,
        'batch_size_days' => 7,
        'total_days' => 0,
        'processed_days' => 0,
        'failed_days' => 0,
        'unavailable_days' => 0,
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'status' => BackfillRunStatus::class,
            'source_ids' => 'array',
            'current_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'pause_requested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<BackfillRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BackfillRunItem::class);
    }

    /** @return HasMany<BackfillReparseRun, $this> */
    public function reparseRuns(): HasMany
    {
        return $this->hasMany(BackfillReparseRun::class);
    }
}
