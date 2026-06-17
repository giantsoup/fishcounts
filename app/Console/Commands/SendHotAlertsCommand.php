<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:send-hot-alerts {date?}')]
#[Description('Dispatch hot bite alert notifications for threshold-crossing score results.')]
class SendHotAlertsCommand extends Command
{
    public function handle(): int
    {
        $this->info('Hot alert dispatch pipeline is ready.');

        return self::SUCCESS;
    }
}
