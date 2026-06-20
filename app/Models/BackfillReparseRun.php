<?php

namespace App\Models;

use App\Enums\BackfillReparseRunStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class BackfillReparseRun extends Model
{
    protected $attributes = [
        'status' => BackfillReparseRunStatus::Pending->value,
        'total_payloads' => 0,
        'queued_payloads' => 0,
        'completed_payloads' => 0,
        'failed_payloads' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => BackfillReparseRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BackfillRun, $this> */
    public function backfillRun(): BelongsTo
    {
        return $this->belongsTo(BackfillRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
