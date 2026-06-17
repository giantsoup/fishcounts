<?php

namespace App\Jobs;

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
        BackfillRunItem::query()
            ->where('backfill_run_id', $this->backfillRunId)
            ->whereDate('target_date', $this->date)
            ->where('status', 'pending')
            ->pluck('id')
            ->each(fn (int $itemId): mixed => BackfillSourceDateJob::dispatch($itemId));
    }
}
