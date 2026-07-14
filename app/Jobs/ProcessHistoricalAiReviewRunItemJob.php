<?php

namespace App\Jobs;

use App\Enums\HistoricalAiReviewRunItemStatus;
use App\Enums\HistoricalAiReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Models\HistoricalAiReviewRun;
use App\Models\HistoricalAiReviewRunItem;
use App\Services\AI\HistoricalAiReviewRunItemFinalizer;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessHistoricalAiReviewRunItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

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

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(HistoricalAiReviewRunItemFinalizer $finalizer): void
    {
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
            $finalizer->fail($this->historicalAiReviewRunItemId, 'The selected payload is unavailable or changed.');

            return;
        }

        $diagnosticFingerprints = $this->eligibleDiagnosticFingerprints($item);

        if ($diagnosticFingerprints->isEmpty()) {
            $finalizer->complete($this->historicalAiReviewRunItemId);

            return;
        }

        $currentEstimatedCost = (int) config('fish.ai_review.budgets.estimated_request_cost_micros');
        $chunks = $diagnosticFingerprints
            ->chunk(max(1, (int) config('fish.ai_review.limits.max_diagnostics_per_request')))
            ->values();
        $estimatedAttemptCost = $currentEstimatedCost * $chunks->count();

        if ($currentEstimatedCost <= 0 || $currentEstimatedCost > $item->run->estimated_item_cost_micros) {
            $finalizer->fail($this->historicalAiReviewRunItemId, 'The configured AI request estimate exceeds this run’s authorized per-request amount.');

            return;
        }

        $startedItem = $this->startAttempt($item, $estimatedAttemptCost);

        if ($startedItem === null) {
            return;
        }

        $item = $startedItem->load('rawScrapePayload');

        $item->rawScrapePayload->parserDiagnosticReviews()
            ->whereHas('parserError', fn ($query) => $query->whereNull('resolution_type'))
            ->whereIn('diagnostic_fingerprint', $diagnosticFingerprints)
            ->whereIn('status', [
                ParserDiagnosticReviewStatus::Failed,
                ParserDiagnosticReviewStatus::Refused,
                ParserDiagnosticReviewStatus::Stale,
                ParserDiagnosticReviewStatus::Skipped,
            ])
            ->get()
            ->each->prepareForRetry();

        $jobs = $chunks->map(fn (Collection $chunk): ReviewParserDiagnosticsJob => new ReviewParserDiagnosticsJob(
            rawScrapePayloadId: $item->raw_scrape_payload_id,
            diagnosticFingerprints: $chunk->values()->all(),
            finalizeParserDiagnosticReviewRun: false,
            uniqueContext: "historical-item:{$item->id}",
        ))
            ->push(new FinalizeHistoricalAiReviewRunItemJob($item->id));
        $historicalAiReviewRunItemId = $item->id;

        Bus::chain($jobs->all())
            ->catch(static function (Throwable $throwable) use ($historicalAiReviewRunItemId): void {
                app(HistoricalAiReviewRunItemFinalizer::class)->fail($historicalAiReviewRunItemId, $throwable);
            })
            ->onConnection('database')
            ->onQueue('ai-parsing')
            ->dispatch();
    }

    public function failed(?Throwable $throwable): void
    {
        app(HistoricalAiReviewRunItemFinalizer::class)->fail(
            $this->historicalAiReviewRunItemId,
            $throwable ?? 'Historical AI review item failed.',
        );
    }

    private function startAttempt(HistoricalAiReviewRunItem $item, int $estimatedAttemptCost): ?HistoricalAiReviewRunItem
    {
        return DB::transaction(function () use ($item, $estimatedAttemptCost): ?HistoricalAiReviewRunItem {
            $lockedItem = HistoricalAiReviewRunItem::query()->lockForUpdate()->findOrFail($item->id);
            $run = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($lockedItem->historical_ai_review_run_id);

            if ($run->status !== HistoricalAiReviewRunStatus::Running
                || in_array($lockedItem->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
                return null;
            }

            if ($run->estimated_spent_micros + $estimatedAttemptCost > $run->budget_micros) {
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
            $run->estimated_spent_micros += $estimatedAttemptCost;
            $run->save();

            return $lockedItem->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, string> */
    private function eligibleDiagnosticFingerprints(HistoricalAiReviewRunItem $item): Collection
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
            ->orderBy('id')
            ->pluck('diagnostic_fingerprint')
            ->unique()
            ->values();
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
