<?php

namespace App\Models;

use App\Enums\ParserDiagnosticReviewRunStatus;
use Database\Factories\ParserDiagnosticReviewRunFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

#[Guarded(['id'])]
class ParserDiagnosticReviewRun extends Model
{
    /** @use HasFactory<ParserDiagnosticReviewRunFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'preparing',
    ];

    protected function casts(): array
    {
        return [
            'status' => ParserDiagnosticReviewRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function markQueued(): void
    {
        $this->transitionFrom(
            [ParserDiagnosticReviewRunStatus::Preparing],
            ['status' => ParserDiagnosticReviewRunStatus::Queued->value],
        );
    }

    public function markRunning(): void
    {
        $this->transitionFrom(
            [ParserDiagnosticReviewRunStatus::Preparing, ParserDiagnosticReviewRunStatus::Queued],
            [
                'status' => ParserDiagnosticReviewRunStatus::Running->value,
                'started_at' => now(),
            ],
        );
    }

    public function markCompleted(): void
    {
        $this->transitionFrom(
            [
                ParserDiagnosticReviewRunStatus::Preparing,
                ParserDiagnosticReviewRunStatus::Queued,
                ParserDiagnosticReviewRunStatus::Running,
            ],
            [
                'status' => ParserDiagnosticReviewRunStatus::Completed->value,
                'completed_at' => now(),
            ],
        );
    }

    public function markFailed(Throwable|string $failure): void
    {
        $message = $failure instanceof Throwable ? $failure->getMessage() : $failure;
        $message = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $message) ?? 'AI review failed.';

        $this->transitionFrom(
            [
                ParserDiagnosticReviewRunStatus::Preparing,
                ParserDiagnosticReviewRunStatus::Queued,
                ParserDiagnosticReviewRunStatus::Running,
            ],
            [
                'status' => ParserDiagnosticReviewRunStatus::Failed->value,
                'failed_at' => now(),
                'failure_message' => str($message)->limit(
                    (int) config('fish.ai_review.limits.max_failure_message_length'),
                    '',
                )->toString(),
            ],
        );
    }

    public function isStale(): bool
    {
        return $this->status->isActive()
            && $this->updated_at->lte(now()->subMinutes(max(1, (int) config('fish.ai_review.operations.manual_run_stale_minutes'))));
    }

    /**
     * @param  array<int, ParserDiagnosticReviewRunStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function transitionFrom(array $from, array $attributes): void
    {
        self::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(
                static fn (ParserDiagnosticReviewRunStatus $status): string => $status->value,
                $from,
            ))
            ->update($attributes);

        $this->refresh();
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
