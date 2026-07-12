<?php

namespace App\Console\Commands;

use App\Enums\ScoreRunStatus;
use App\Jobs\ComputeScoreForRuleJob;
use App\Models\AlertRule;
use App\Models\ScoreRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:score-latest {date?}')]
#[Description('Create a score run, defaulting to the previous completed day.')]
class ScoreLatestCommand extends Command
{
    public function handle(): int
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $date = CarbonImmutable::parse(
            $this->argument('date') ?: CarbonImmutable::now($timezone)->subDay(),
            $timezone,
        )->toDateString();
        $scoreRun = ScoreRun::query()->firstOrCreate(['run_date' => $date], ['status' => ScoreRunStatus::Pending, 'started_at' => now()]);

        AlertRule::query()
            ->where('is_enabled', true)
            ->pluck('id')
            ->each(fn (int $ruleId): mixed => ComputeScoreForRuleJob::dispatch($ruleId, $date, $scoreRun->id));

        $this->info("Score jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
