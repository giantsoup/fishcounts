<?php

namespace App\Models;

use App\Enums\HistoricalAiReviewRunStatus;
use Database\Factories\HistoricalAiReviewRunFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class HistoricalAiReviewRun extends Model
{
    /** @use HasFactory<HistoricalAiReviewRunFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'selected_count' => 0,
        'dispatched_count' => 0,
        'completed_count' => 0,
        'failed_count' => 0,
        'estimated_spent_micros' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => HistoricalAiReviewRunStatus::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'max_items' => 'integer',
            'budget_micros' => 'integer',
            'estimated_item_cost_micros' => 'integer',
            'selected_count' => 'integer',
            'dispatched_count' => 'integer',
            'completed_count' => 'integer',
            'failed_count' => 'integer',
            'estimated_spent_micros' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'stopped_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<HistoricalAiReviewRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(HistoricalAiReviewRunItem::class);
    }
}
