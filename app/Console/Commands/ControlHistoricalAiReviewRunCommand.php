<?php

namespace App\Console\Commands;

use App\Enums\HistoricalAiReviewRunStatus;
use App\Models\HistoricalAiReviewRun;
use App\Services\AI\HistoricalAiReviewDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ai-reviews:historical {run : Historical AI review run ID} {action : pause, resume, or stop}')]
#[Description('Safely pause, resume, or gracefully stop a historical AI review run')]
class ControlHistoricalAiReviewRunCommand extends Command
{
    public function handle(HistoricalAiReviewDispatcher $dispatcher): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['pause', 'resume', 'stop'], true)) {
            $this->error('Action must be pause, resume, or stop.');

            return self::INVALID;
        }

        $run = HistoricalAiReviewRun::query()->find((int) $this->argument('run'));

        if ($run === null) {
            $this->error('Historical AI review run not found.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($run, $action): void {
            $lockedRun = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($action === 'pause' && $lockedRun->status === HistoricalAiReviewRunStatus::Running) {
                $lockedRun->forceFill(['status' => HistoricalAiReviewRunStatus::Paused, 'paused_at' => now()])->save();
            }

            if ($action === 'resume' && $lockedRun->status === HistoricalAiReviewRunStatus::Paused) {
                $isFinished = $lockedRun->completed_count + $lockedRun->failed_count >= $lockedRun->selected_count;
                $lockedRun->forceFill([
                    'status' => $isFinished
                        ? ($lockedRun->failed_count > 0 ? HistoricalAiReviewRunStatus::Failed : HistoricalAiReviewRunStatus::Completed)
                        : HistoricalAiReviewRunStatus::Running,
                    'paused_at' => null,
                    'completed_at' => $isFinished ? now() : null,
                ])->save();
            }

            if ($action === 'stop' && in_array($lockedRun->status, [HistoricalAiReviewRunStatus::Running, HistoricalAiReviewRunStatus::Paused], true)) {
                $lockedRun->forceFill(['status' => HistoricalAiReviewRunStatus::Stopped, 'stopped_at' => now()])->save();
            }
        }, attempts: 3);

        $run->refresh();

        if ($action === 'resume' && $run->status === HistoricalAiReviewRunStatus::Running) {
            $dispatcher->dispatchPending($run);
        }

        $this->info("Run {$run->id} is {$run->status->value}.");

        return self::SUCCESS;
    }
}
