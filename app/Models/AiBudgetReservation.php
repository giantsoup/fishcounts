<?php

namespace App\Models;

use App\Enums\AiBudgetReservationStatus;
use Database\Factories\AiBudgetReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class AiBudgetReservation extends Model
{
    /** @use HasFactory<AiBudgetReservationFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'reserved',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiBudgetReservationStatus::class,
            'reserved_micros' => 'integer',
            'actual_micros' => 'integer',
            'reserved_at' => 'datetime',
            'settled_at' => 'datetime',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiBudgetPeriod, $this> */
    public function budgetPeriod(): BelongsTo
    {
        return $this->belongsTo(AiBudgetPeriod::class, 'ai_budget_period_id');
    }

    /** @return BelongsTo<AiBudgetPeriod, $this> */
    public function dailyBudgetPeriod(): BelongsTo
    {
        return $this->belongsTo(AiBudgetPeriod::class, 'daily_ai_budget_period_id');
    }

    /** @return BelongsTo<ParserDiagnosticReview, $this> */
    public function parserDiagnosticReview(): BelongsTo
    {
        return $this->belongsTo(ParserDiagnosticReview::class);
    }
}
