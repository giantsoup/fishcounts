<?php

namespace App\Jobs;

use App\Actions\Parsing\AutomateParserDiagnosticReviews;
use App\Contracts\AI\ParserDiagnosticReviewer;
use App\Enums\HistoricalAiReviewRunItemStatus;
use App\Enums\HistoricalAiReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Models\HistoricalAiReviewRun;
use App\Models\HistoricalAiReviewRunItem;
use App\Services\AI\AiBudgetManager;
use App\Services\AI\ParserDiagnosticReviewRequestFactory;
use App\Services\AI\ParserDiagnosticReviewRequestValidator;
use App\Services\AI\ParserDiagnosticReviewResultValidator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessHistoricalAiReviewRunItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 220;

    public function __construct(public int $historicalAiReviewRunItemId)
    {
        $this->onConnection('database');
        $this->onQueue('ai-parsing');
    }

    public function uniqueId(): string
    {
        return (string) $this->historicalAiReviewRunItemId;
    }

    public function uniqueVia(): Repository
    {
        return cache()->store('database');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new RateLimited('ai-parser-reviews'))->releaseAfter(60)];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        ParserDiagnosticReviewer $reviewer,
        ParserDiagnosticReviewRequestFactory $requestFactory,
        ParserDiagnosticReviewRequestValidator $requestValidator,
        ParserDiagnosticReviewResultValidator $resultValidator,
        AiBudgetManager $budgetManager,
        AutomateParserDiagnosticReviews $automateParserDiagnosticReviews,
    ): void {
        $item = HistoricalAiReviewRunItem::query()->with(['run', 'rawScrapePayload'])->find($this->historicalAiReviewRunItemId);

        if ($item === null
            || in_array($item->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
            return;
        }

        if ($item->run->status !== HistoricalAiReviewRunStatus::Running) {
            if ($item->run->status === HistoricalAiReviewRunStatus::Paused) {
                $item->forceFill(['dispatched_at' => null])->save();
            }

            return;
        }

        if (! config('fish.ai_review.enabled')
            || ! config('fish.ai_review.dispatch_enabled')
            || blank(config('services.openai.api_key'))) {
            $item->forceFill(['dispatched_at' => null])->save();

            return;
        }

        if ($item->rawScrapePayload === null || ! hash_equals($item->payload_hash, $item->rawScrapePayload->payload_hash)) {
            $this->markFailed('The selected payload is unavailable or changed.');

            return;
        }

        if (! $this->hasEligibleWork($item)) {
            $this->markCompletedWithoutAttempt($item);

            return;
        }

        $currentEstimatedCost = (int) config('fish.ai_review.budgets.estimated_request_cost_micros');

        if ($currentEstimatedCost <= 0 || $currentEstimatedCost > $item->run->estimated_item_cost_micros) {
            $this->markFailed('The configured AI request estimate exceeds this run’s authorized per-item amount.');

            return;
        }

        $startedItem = $this->startAttempt($item);

        if ($startedItem === null) {
            return;
        }

        $item = $startedItem->load('rawScrapePayload');

        $item->rawScrapePayload->parserDiagnosticReviews()
            ->whereHas('parserError', fn ($query) => $query->whereNull('resolution_type'))
            ->whereIn('status', [
                ParserDiagnosticReviewStatus::Failed,
                ParserDiagnosticReviewStatus::Refused,
                ParserDiagnosticReviewStatus::Stale,
                ParserDiagnosticReviewStatus::Skipped,
            ])
            ->get()
            ->each->prepareForRetry();

        $reviewJob = new ReviewParserDiagnosticsJob($item->raw_scrape_payload_id);
        $reviewJob->handle(
            $reviewer,
            $requestFactory,
            $requestValidator,
            $resultValidator,
            $budgetManager,
            $automateParserDiagnosticReviews,
        );

        $terminalFailure = $item->rawScrapePayload->parserDiagnosticReviews()
            ->whereHas('parserError', fn ($query) => $query->whereNull('resolution_type'))
            ->whereIn('status', [
                ParserDiagnosticReviewStatus::Failed,
                ParserDiagnosticReviewStatus::Stale,
                ParserDiagnosticReviewStatus::Skipped,
            ])
            ->exists();

        if ($terminalFailure) {
            $this->markFailed('The AI review item ended without a reviewable result.');

            return;
        }

        DB::transaction(function () use ($item): void {
            $lockedItem = HistoricalAiReviewRunItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($lockedItem->status !== HistoricalAiReviewRunItemStatus::Running) {
                return;
            }

            $lockedItem->forceFill([
                'status' => HistoricalAiReviewRunItemStatus::Completed,
                'completed_at' => now(),
            ])->save();

            $run = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($lockedItem->historical_ai_review_run_id);
            $run->completed_count++;
            $this->completeRunIfFinished($run);
            $run->save();
        }, attempts: 3);
    }

    public function failed(?Throwable $throwable): void
    {
        $this->markFailed($throwable?->getMessage() ?? 'Historical AI review item failed.');
    }

    private function markFailed(string $message): void
    {
        $message = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $message) ?? 'Historical AI review item failed.';

        DB::transaction(function () use ($message): void {
            $item = HistoricalAiReviewRunItem::query()->lockForUpdate()->find($this->historicalAiReviewRunItemId);

            if ($item === null || in_array($item->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
                return;
            }

            $item->forceFill([
                'status' => HistoricalAiReviewRunItemStatus::Failed,
                'failure_message' => Str::limit($message, 1000, ''),
                'completed_at' => now(),
            ])->save();

            $run = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($item->historical_ai_review_run_id);
            $run->failed_count++;
            $this->completeRunIfFinished($run);
            $run->save();
        }, attempts: 3);
    }

    private function startAttempt(HistoricalAiReviewRunItem $item): ?HistoricalAiReviewRunItem
    {
        return DB::transaction(function () use ($item): ?HistoricalAiReviewRunItem {
            $lockedItem = HistoricalAiReviewRunItem::query()->lockForUpdate()->findOrFail($item->id);
            $run = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($lockedItem->historical_ai_review_run_id);

            if ($run->status !== HistoricalAiReviewRunStatus::Running
                || in_array($lockedItem->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
                return null;
            }

            if ($run->estimated_spent_micros + $run->estimated_item_cost_micros > $run->budget_micros) {
                $lockedItem->forceFill([
                    'status' => HistoricalAiReviewRunItemStatus::Failed,
                    'failure_message' => 'The historical run hard budget was exhausted before another provider attempt.',
                    'completed_at' => now(),
                ])->save();
                $run->failed_count++;
                $this->completeRunIfFinished($run);
                $run->save();

                return null;
            }

            $lockedItem->forceFill([
                'status' => HistoricalAiReviewRunItemStatus::Running,
                'attempts' => $lockedItem->attempts + 1,
                'started_at' => now(),
                'failure_message' => null,
            ])->save();
            $run->estimated_spent_micros += $run->estimated_item_cost_micros;
            $run->save();

            return $lockedItem->refresh();
        }, attempts: 3);
    }

    private function hasEligibleWork(HistoricalAiReviewRunItem $item): bool
    {
        return $item->rawScrapePayload->parserErrors()
            ->whereNull('resolution_type')
            ->whereNotNull('diagnostic_fingerprint')
            ->where(function ($query): void {
                $query->whereDoesntHave('diagnosticReviews')
                    ->orWhereHas('diagnosticReviews', fn ($query) => $query->whereIn('status', [
                        ParserDiagnosticReviewStatus::Failed,
                        ParserDiagnosticReviewStatus::Refused,
                        ParserDiagnosticReviewStatus::Stale,
                        ParserDiagnosticReviewStatus::Skipped,
                    ]));
            })
            ->exists();
    }

    private function markCompletedWithoutAttempt(HistoricalAiReviewRunItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $lockedItem = HistoricalAiReviewRunItem::query()->lockForUpdate()->findOrFail($item->id);
            $run = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($lockedItem->historical_ai_review_run_id);

            if ($run->status !== HistoricalAiReviewRunStatus::Running
                || in_array($lockedItem->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
                return;
            }

            $lockedItem->forceFill([
                'status' => HistoricalAiReviewRunItemStatus::Completed,
                'completed_at' => now(),
            ])->save();
            $run->completed_count++;
            $this->completeRunIfFinished($run);
            $run->save();
        }, attempts: 3);
    }

    private function completeRunIfFinished(HistoricalAiReviewRun $run): void
    {
        if ($run->completed_count + $run->failed_count >= $run->selected_count
            && $run->status === HistoricalAiReviewRunStatus::Running) {
            $run->status = $run->failed_count > 0
                ? HistoricalAiReviewRunStatus::Failed
                : HistoricalAiReviewRunStatus::Completed;
            $run->completed_at = now();
        }
    }
}
