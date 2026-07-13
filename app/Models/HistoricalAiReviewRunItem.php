<?php

namespace App\Models;

use App\Enums\HistoricalAiReviewRunItemStatus;
use Database\Factories\HistoricalAiReviewRunItemFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class HistoricalAiReviewRunItem extends Model
{
    /** @use HasFactory<HistoricalAiReviewRunItemFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => HistoricalAiReviewRunItemStatus::class,
            'attempts' => 'integer',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<HistoricalAiReviewRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(HistoricalAiReviewRun::class, 'historical_ai_review_run_id');
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }
}
