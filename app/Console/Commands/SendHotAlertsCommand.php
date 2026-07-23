<?php

namespace App\Console\Commands;

use App\Enums\ScoreRunStatus;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Services\Notifications\AlertNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:send-hot-alerts {date?}')]
#[Description('Dispatch threshold-crossing alerts, defaulting to the previous completed day.')]
class SendHotAlertsCommand extends Command
{
    public function handle(AlertNotificationService $notificationService): int
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $date = CarbonImmutable::parse(
            $this->argument('date') ?: CarbonImmutable::now($timezone)->subDay(),
            $timezone,
        );
        $scoreRun = ScoreRun::query()->whereDate('run_date', $date->toDateString())->first();
        $expectedResults = AlertRule::query()->where('is_enabled', true)->count();
        $completedResults = ScoreResult::query()
            ->whereDate('score_date', $date->toDateString())
            ->whereHas('alertRule', fn ($query) => $query->where('is_enabled', true))
            ->count();
        if (! $scoreRun instanceof ScoreRun || $completedResults < $expectedResults) {
            $this->warn("Hot alerts were not queued because scoring for {$date->toDateString()} is incomplete.");

            return self::FAILURE;
        }

        $scoreRun->update([
            'status' => ScoreRunStatus::Succeeded,
            'finished_at' => $scoreRun->finished_at ?? now(),
        ]);
        $count = $notificationService->dispatchThresholdCrossings($date);

        $this->info("Queued {$count} hot alert delivery jobs for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
