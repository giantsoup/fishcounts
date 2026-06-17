<?php

namespace App\Models;

use App\Enums\AlertEventStatus;
use App\Enums\AlertEventType;
use App\Enums\ScoreLevel;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class AlertEvent extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AlertEventType::class,
            'event_date' => 'date',
            'level' => ScoreLevel::class,
            'email_sent_at' => 'datetime',
            'discord_sent_at' => 'datetime',
            'status' => AlertEventStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    /** @return BelongsTo<ScoreResult, $this> */
    public function scoreResult(): BelongsTo
    {
        return $this->belongsTo(ScoreResult::class);
    }
}
