<?php

namespace App\Jobs;

use App\Enums\BackfillRunStatus;
use App\Models\BackfillRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class BackfillRunJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 1;

    public function __construct(public int $backfillRunId)
    {
        $this->onQueue('backfill');
    }

    public function uniqueId(): string
    {
        return (string) $this->backfillRunId;
    }

    public function handle(): void
    {
        $backfill = BackfillRun::query()->findOrFail($this->backfillRunId);

        if (in_array($backfill->status, [BackfillRunStatus::Cancelled, BackfillRunStatus::Paused], true)) {
            return;
        }

        $backfill->update(['status' => BackfillRunStatus::Running, 'started_at' => $backfill->started_at ?? now()]);

        $backfill->items()
            ->where('status', BackfillRunStatus::Pending->value)
            ->orderBy('target_date')
            ->pluck('target_date')
            ->unique()
            ->each(fn ($date): mixed => BackfillDateJob::dispatch($backfill->id, $date->toDateString()));
    }

    public function failed(Throwable $throwable): void
    {
        BackfillRun::query()->whereKey($this->backfillRunId)->update([
            'status' => BackfillRunStatus::Failed,
            'finished_at' => now(),
            'error_message' => str($throwable->getMessage())->limit(1000)->toString(),
        ]);
    }
}
