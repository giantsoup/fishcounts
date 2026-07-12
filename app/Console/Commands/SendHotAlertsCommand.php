<?php

namespace App\Console\Commands;

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
        $count = $notificationService->dispatchThresholdCrossings($date);

        $this->info("Queued {$count} hot alert delivery jobs for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
