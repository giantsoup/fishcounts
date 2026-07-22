<?php

namespace App\Models;

use App\Enums\ParserReparseRunStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class ParserReparseRun extends Model
{
    protected $attributes = [
        'status' => ParserReparseRunStatus::Pending->value,
        'initial_open_errors' => 0,
        'initial_alias_errors' => 0,
        'initial_structural_errors' => 0,
        'initial_payloads' => 0,
        'affected_dates' => 0,
        'total_items' => 0,
        'queued_items' => 0,
        'completed_items' => 0,
        'failed_items' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ParserReparseRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return HasMany<ParserReparseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ParserReparseItem::class);
    }
}
