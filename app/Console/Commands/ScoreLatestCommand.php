<?php

namespace App\Console\Commands;

use App\Enums\ScoreRunStatus;
use App\Jobs\ComputeScoreForRuleJob;
use App\Models\AlertRule;
use App\Models\ScoreRun;
use App\Services\Parsing\TripReportNormalizer;
use App\Services\Scoring\DailyScoreReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:score-latest {date?}')]
#[Description('Create a score run, defaulting to the previous completed day.')]
class ScoreLatestCommand extends Command
{
    public function handle(DailyScoreReadiness $readiness, TripReportNormalizer $normalizer): int
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $date = CarbonImmutable::parse(
            $this->argument('date') ?: CarbonImmutable::now($timezone)->subDay(),
            $timezone,
        )->toDateString();
        $incompleteSources = $readiness->incompleteSourceNames(CarbonImmutable::parse($date, $timezone));
        if ($incompleteSources !== []) {
            $this->warn('Scoring was not queued because daily parsing is incomplete for: '.implode(', ', $incompleteSources).'.');

            return self::FAILURE;
        }

        $normalizer->refreshPrimaryReports($date);
        $scoreRun = ScoreRun::query()->firstOrCreate(['run_date' => $date], ['status' => ScoreRunStatus::Pending, 'started_at' => now()]);

        AlertRule::query()
            ->where('is_enabled', true)
            ->pluck('id')
            ->each(fn (int $ruleId): mixed => ComputeScoreForRuleJob::dispatch($ruleId, $date, $scoreRun->id));

        $this->info("Score jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
