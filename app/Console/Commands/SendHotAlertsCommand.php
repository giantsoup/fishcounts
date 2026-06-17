<?php

namespace App\Console\Commands;

use App\Services\Notifications\AlertNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:send-hot-alerts {date?}')]
#[Description('Dispatch hot bite alert notifications for threshold-crossing score results.')]
class SendHotAlertsCommand extends Command
{
    public function handle(AlertNotificationService $notificationService): int
    {
        $date = CarbonImmutable::parse($this->argument('date') ?: today());
        $count = $notificationService->dispatchThresholdCrossings($date);

        $this->info("Queued {$count} hot alert delivery jobs for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
