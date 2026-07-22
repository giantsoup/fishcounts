<?php

namespace App\Jobs;

use App\Actions\Parsing\RefreshParserReparseRunProgress;
use App\Enums\ParserReparseItemStatus;
use App\Enums\ParserReparseRunStatus;
use App\Models\ParserReparseRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchParserReparseRunJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $parserReparseRunId)
    {
        $this->onQueue('parsing');
    }

    public function uniqueId(): string
    {
        return (string) $this->parserReparseRunId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(RefreshParserReparseRunProgress $refreshProgress): void
    {
        $dates = DB::transaction(function (): array {
            $run = ParserReparseRun::query()->lockForUpdate()->findOrFail($this->parserReparseRunId);

            if (! $run->status->isActive()) {
                return [];
            }

            $dates = $run->items()
                ->whereIn('status', [ParserReparseItemStatus::Pending, ParserReparseItemStatus::Running])
                ->select('target_date')
                ->distinct()
                ->orderBy('target_date')
                ->pluck('target_date')
                ->map->toDateString()
                ->all();

            $run->update([
                'status' => ParserReparseRunStatus::Running,
                'queued_items' => $run->items()->count(),
                'started_at' => $run->started_at ?? now(),
                'finished_at' => null,
                'error_message' => null,
            ]);

            return $dates;
        }, attempts: 3);

        if ($dates === []) {
            $refreshProgress->handle($this->parserReparseRunId);

            return;
        }

        foreach ($dates as $date) {
            ProcessParserReparseDateJob::dispatch($this->parserReparseRunId, $date)->afterCommit();
        }
    }

    public function failed(Throwable $throwable): void
    {
        $failedItems = ParserReparseRun::query()->find($this->parserReparseRunId)?->items()
            ->whereIn('status', [ParserReparseItemStatus::Pending, ParserReparseItemStatus::Running])
            ->update([
                'status' => ParserReparseItemStatus::Failed,
                'finished_at' => now(),
                'error_message' => str($throwable->getMessage())->limit(1000, '')->toString(),
            ]) ?? 0;

        if ($failedItems > 0) {
            app(RefreshParserReparseRunProgress::class)->handle($this->parserReparseRunId);
        }

        ParserReparseRun::query()->whereKey($this->parserReparseRunId)->update([
            'status' => ParserReparseRunStatus::Failed,
            'finished_at' => now(),
            'error_message' => str($throwable->getMessage())->limit(1000, '')->toString(),
        ]);
    }
}
