<?php

namespace App\Models;

use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Exceptions\InvalidParserDiagnosticReviewTransition;
use Database\Factories\ParserDiagnosticReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
            $attributes['failure_category'] = null;
        }

        if ($status === ParserDiagnosticReviewStatus::Succeeded || $status === ParserDiagnosticReviewStatus::Refused) {
            $attributes['completed_at'] = now();
        }

        if ($status === ParserDiagnosticReviewStatus::Failed) {
            $attributes['failed_at'] = now();
        }

        $this->forceFill($attributes)->save();
    }

    public function fail(string $message, string $category = 'application'): void
    {
        $this->failure_message = Str::limit(
            $message,
            (int) config('fish.ai_review.limits.max_failure_message_length'),
            '',
        );
        $this->failure_category = $category;

        $this->transitionTo(ParserDiagnosticReviewStatus::Failed);
    }

    public function prepareForRetry(): void
    {
        $this->transitionTo(ParserDiagnosticReviewStatus::Pending);
        $this->forceFill([
            'parser_bug_report_id' => null,
            'classification' => null,
            'confidence' => null,
            'validated_result' => null,
            'rationale' => null,
            'response_id' => null,
            'input_tokens' => null,
            'cached_input_tokens' => 0,
            'output_tokens' => null,
            'reasoning_tokens' => 0,
            'total_tokens' => null,
            'estimated_cost_micros' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'failure_message' => null,
            'failure_category' => null,
        ])->save();
    }

    /** @return null|array{type: string, id: int} */
    public function recommendedCanonicalTarget(): ?array
    {
        $aliasCorrections = collect($this->validated_result['corrections'] ?? [])
            ->filter(fn (array $correction): bool => ($correction['operation'] ?? null) === ParserCorrectionOperation::MapAlias->value)
            ->values();

        if ($aliasCorrections->count() !== 1) {
            return null;
        }

        $correction = $aliasCorrections->first();

        return is_string($correction['canonical_type'] ?? null) && is_int($correction['canonical_id'] ?? null)
            ? ['type' => $correction['canonical_type'], 'id' => $correction['canonical_id']]
            : null;
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

    /** @return BelongsTo<ParserBugReport, $this> */
    public function parserBugReport(): BelongsTo
    {
        return $this->belongsTo(ParserBugReport::class);
    }

    /** @return HasMany<AiBudgetReservation, $this> */
    public function budgetReservations(): HasMany
    {
        return $this->hasMany(AiBudgetReservation::class);
    }

    /** @return HasMany<ParserDiagnosticReviewAction, $this> */
    public function humanActions(): HasMany
    {
        return $this->hasMany(ParserDiagnosticReviewAction::class);
    }

    /** @return HasOne<ParserReportOverride, $this> */
    public function reportOverride(): HasOne
    {
        return $this->hasOne(ParserReportOverride::class)->latestOfMany();
    }
}
