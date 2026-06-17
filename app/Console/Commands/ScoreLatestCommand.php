<?php

namespace App\Console\Commands;

use App\Models\ScoreRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:score-latest')]
#[Description('Create the latest score run envelope.')]
class ScoreLatestCommand extends Command
{
    public function handle(): int
    {
        ScoreRun::query()->firstOrCreate(['run_date' => today()], ['status' => 'pending']);
        $this->info('Score run recorded.');

        return self::SUCCESS;
    }
}
