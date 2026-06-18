<?php

namespace App\Jobs;

use App\Enums\BackfillRunStatus;
use App\Enums\ScrapeRunStatus;
use App\Enums\ScrapeRunType;
use App\Models\BackfillRun;
use App\Models\BackfillRunItem;
use App\Models\ScrapeRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillSourceDateJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $backfillRunItemId)
    {
        $this->onQueue('backfill');
    }

    public function uniqueId(): string
    {
        return (string) $this->backfillRunItemId;
    }

    public function handle(): void
    {
        $item = BackfillRunItem::query()->with('backfillRun')->findOrFail($this->backfillRunItemId);

        if (in_array($item->backfillRun->status, [BackfillRunStatus::Cancelled, BackfillRunStatus::Paused], true)) {
            return;
        }

        $item->update(['status' => BackfillRunStatus::Running, 'started_at' => now()]);

        ScrapeSourceForDateJob::dispatchSync(
            $item->scrape_source_id,
            $item->target_date->toDateString(),
            ScrapeRunType::Backfill,
        );

        $scrapeRun = $this->latestScrapeRun($item);
        $rawPayloadId = $scrapeRun?->metadata['raw_scrape_payload_id'] ?? null;
        $status = $scrapeRun?->status === ScrapeRunStatus::Unavailable
            ? BackfillRunStatus::Unavailable
            : BackfillRunStatus::Succeeded;

        $item->update([
            'status' => $status,
            'scrape_run_id' => $scrapeRun?->id,
            'raw_scrape_payload_id' => is_int($rawPayloadId) ? $rawPayloadId : null,
            'finished_at' => now(),
            'error_message' => $status === BackfillRunStatus::Unavailable ? ($scrapeRun?->metadata['reason'] ?? null) : null,
        ]);

        $this->finalizeBackfill($item->backfillRun);
    }

    public function failed(Throwable $throwable): void
    {
        $item = BackfillRunItem::query()->find($this->backfillRunItemId);

        if ($item !== null) {
            $item->update([
                'status' => BackfillRunStatus::Failed,
                'finished_at' => now(),
                'error_message' => str($throwable->getMessage())->limit(1000)->toString(),
            ]);

            $this->finalizeBackfill($item->backfillRun);
        }
    }

    private function latestScrapeRun(BackfillRunItem $item): ?ScrapeRun
    {
        return ScrapeRun::query()
            ->where('scrape_source_id', $item->scrape_source_id)
            ->whereDate('target_date', $item->target_date)
            ->where('run_type', ScrapeRunType::Backfill)
            ->latest()
            ->first();
    }

    private function finalizeBackfill(BackfillRun $backfill): void
    {
        DB::transaction(function () use ($backfill): void {
            $lockedBackfill = BackfillRun::query()->lockForUpdate()->findOrFail($backfill->id);

            if (in_array($lockedBackfill->status, [BackfillRunStatus::Cancelled, BackfillRunStatus::Paused], true)) {
                return;
            }

            $counts = $lockedBackfill->items()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $pendingCount = (int) ($counts[BackfillRunStatus::Pending->value] ?? 0);
            $runningCount = (int) ($counts[BackfillRunStatus::Running->value] ?? 0);
            $failedCount = (int) ($counts[BackfillRunStatus::Failed->value] ?? 0);
            $unavailableCount = (int) ($counts[BackfillRunStatus::Unavailable->value] ?? 0);
            $succeededCount = (int) ($counts[BackfillRunStatus::Succeeded->value] ?? 0);
            $finishedCount = $succeededCount + $failedCount + $unavailableCount;

            $updates = [
                'processed_days' => $succeededCount,
                'failed_days' => $failedCount,
                'unavailable_days' => $unavailableCount,
            ];

            if ($pendingCount === 0 && $runningCount === 0) {
                $updates['status'] = $failedCount > 0 ? BackfillRunStatus::Failed : BackfillRunStatus::Succeeded;
                $updates['finished_at'] = now();
                $updates['error_message'] = $failedCount > 0 ? "{$failedCount} backfill item(s) failed." : null;
            } elseif ($finishedCount > 0) {
                $updates['status'] = BackfillRunStatus::Running;
            }

            $lockedBackfill->update($updates);
        });
    }
}
