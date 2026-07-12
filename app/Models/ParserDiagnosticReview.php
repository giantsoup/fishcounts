<?php

namespace App\Models;

use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Exceptions\InvalidParserDiagnosticReviewTransition;
use Database\Factories\ParserDiagnosticReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['id'])]
class ParserDiagnosticReview extends Model
{
    /** @use HasFactory<ParserDiagnosticReviewFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'provider' => 'openai',
        'cached_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ParserDiagnosticReviewStatus::class,
            'classification' => ParserDiagnosticReviewClassification::class,
            'confidence' => 'decimal:4',
            'validated_result' => 'array',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost_micros' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function transitionTo(ParserDiagnosticReviewStatus $status): void
    {
        if (! $this->status->canTransitionTo($status)) {
            throw new InvalidParserDiagnosticReviewTransition($this->status, $status);
        }

        $attributes = ['status' => $status];

        if ($status === ParserDiagnosticReviewStatus::Running) {
            $attributes['attempts'] = $this->attempts + 1;
            $attributes['started_at'] = now();
            $attributes['failed_at'] = null;
            $attributes['failure_message'] = null;
        }

        if ($status === ParserDiagnosticReviewStatus::Succeeded || $status === ParserDiagnosticReviewStatus::Refused) {
            $attributes['completed_at'] = now();
        }

        if ($status === ParserDiagnosticReviewStatus::Failed) {
            $attributes['failed_at'] = now();
        }

        $this->forceFill($attributes)->save();
    }

    public function fail(string $message): void
    {
        $this->failure_message = Str::limit(
            $message,
            (int) config('fish.ai_review.limits.max_failure_message_length'),
            '',
        );

        $this->transitionTo(ParserDiagnosticReviewStatus::Failed);
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }

    /** @return BelongsTo<ParserError, $this> */
    public function parserError(): BelongsTo
    {
        return $this->belongsTo(ParserError::class);
    }

    /** @return HasMany<AiBudgetReservation, $this> */
    public function budgetReservations(): HasMany
    {
        return $this->hasMany(AiBudgetReservation::class);
    }
}
