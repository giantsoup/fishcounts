<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:send-weekly-digest {--date=}')]
#[Description('Dispatch weekly fishing digest notifications.')]
class SendWeeklyDigestCommand extends Command
{
    public function handle(): int
    {
        $this->info('Weekly digest dispatch pipeline is ready.');

        return self::SUCCESS;
    }
}
