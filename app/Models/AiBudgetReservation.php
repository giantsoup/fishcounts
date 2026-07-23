<?php

namespace App\Models;

use App\Enums\AiBudgetReservationStatus;
use App\Enums\AiParserAttemptCostBasis;
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
        'cost_basis' => 'none',
        'input_tokens' => 0,
        'cached_input_tokens' => 0,
        'cache_write_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
        'total_tokens' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => AiBudgetReservationStatus::class,
            'cost_basis' => AiParserAttemptCostBasis::class,
            'attempt_number' => 'integer',
            'provider_http_status' => 'integer',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'pricing_snapshot' => 'array',
            'reserved_micros' => 'integer',
            'actual_micros' => 'integer',
            'reserved_at' => 'datetime',
            'response_received_at' => 'datetime',
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

    /** @return BelongsTo<ParserExecution, $this> */
    public function parserExecution(): BelongsTo
    {
        return $this->belongsTo(ParserExecution::class);
    }
}
