<?php

namespace App\Jobs;

use App\Enums\BackfillRunStatus;
use App\Enums\ScrapeRunType;
use App\Models\BackfillRunItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
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

        $item->update(['status' => BackfillRunStatus::Succeeded, 'finished_at' => now()]);

        $item->backfillRun()->increment('processed_days');
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

            $item->backfillRun()->increment('failed_days');
        }
    }
}
