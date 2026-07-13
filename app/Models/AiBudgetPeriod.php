<?php

namespace App\Models;

use App\Enums\AiBudgetPeriodType;
use Database\Factories\AiBudgetPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class AiBudgetPeriod extends Model
{
    /** @use HasFactory<AiBudgetPeriodFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'reserved_micros' => 0,
        'spent_micros' => 0,
    ];

    protected function casts(): array
    {
        return [
            'period_type' => AiBudgetPeriodType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'limit_micros' => 'integer',
            'reserved_micros' => 'integer',
            'spent_micros' => 'integer',
        ];
    }

    /** @return HasMany<AiBudgetReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(AiBudgetReservation::class);
    }

    /** @return HasMany<AiBudgetReservation, $this> */
    public function dailyReservations(): HasMany
    {
        return $this->hasMany(AiBudgetReservation::class, 'daily_ai_budget_period_id');
    }
}
