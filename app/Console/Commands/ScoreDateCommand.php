<?php

namespace App\Console\Commands;

use App\Models\ScoreRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:score-date {date}')]
#[Description('Create a score run envelope for a date.')]
class ScoreDateCommand extends Command
{
    public function handle(): int
    {
        $date = CarbonImmutable::parse($this->argument('date'))->toDateString();
        ScoreRun::query()->firstOrCreate(['run_date' => $date], ['status' => 'pending']);
        $this->info("Score run recorded for {$date}.");

        return self::SUCCESS;
    }
}
