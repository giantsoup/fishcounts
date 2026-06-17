<?php

namespace App\Console\Commands;

use App\Jobs\ComputeScoreForRuleJob;
use App\Models\AlertRule;
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
        $scoreRun = ScoreRun::query()->firstOrCreate(['run_date' => $date], ['status' => 'pending', 'started_at' => now()]);

        AlertRule::query()
            ->where('is_enabled', true)
            ->pluck('id')
            ->each(fn (int $ruleId): mixed => ComputeScoreForRuleJob::dispatch($ruleId, $date, $scoreRun->id));

        $this->info("Score jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
