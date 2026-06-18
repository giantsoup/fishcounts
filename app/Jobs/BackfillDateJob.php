<?php

namespace App\Jobs;

use App\Enums\BackfillRunStatus;
use App\Models\BackfillRun;
use App\Models\BackfillRunItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;

class BackfillDateJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public function __construct(public int $backfillRunId, public string $date)
    {
        $this->onQueue('backfill');
    }

    public function uniqueId(): string
    {
        return "{$this->backfillRunId}:{$this->date}";
    }

    public function handle(): void
    {
        $backfill = BackfillRun::query()->findOrFail($this->backfillRunId);

        if (in_array($backfill->status, [BackfillRunStatus::Cancelled, BackfillRunStatus::Paused], true)) {
            return;
        }

        BackfillRunItem::query()
            ->where('backfill_run_id', $this->backfillRunId)
            ->whereDate('target_date', $this->date)
            ->where('status', BackfillRunStatus::Pending->value)
            ->pluck('id')
            ->each(fn (int $itemId): mixed => BackfillSourceDateJob::dispatch($itemId));
    }
}
