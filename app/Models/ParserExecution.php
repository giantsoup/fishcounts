<?php

namespace App\Models;

use App\Enums\ParserEngine;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class ParserExecution extends Model
{
    protected $attributes = [
        'status' => 'running',
        'attempts' => 0,
        'input_tokens' => 0,
        'cached_input_tokens' => 0,
        'cache_write_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
        'total_tokens' => 0,
        'cost_micros' => 0,
        'cost_is_estimated' => false,
    ];

    protected function casts(): array
    {
        return [
            'requested_engine' => ParserEngine::class,
            'selected_engine' => ParserEngine::class,
            'deterministic_snapshot' => 'array',
            'ai_snapshot' => 'array',
            'comparison' => 'array',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_micros' => 'integer',
            'cost_is_estimated' => 'boolean',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'downstream_dispatched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }

    /** @return BelongsTo<ScrapeSource, $this> */
    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class);
    }

    /** @return HasMany<AiBudgetReservation, $this> */
    public function aiAttempts(): HasMany
    {
        return $this->hasMany(AiBudgetReservation::class)->orderBy('attempt_number');
    }
}
