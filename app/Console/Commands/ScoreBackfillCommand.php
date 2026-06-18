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

#[Signature('fish:score-backfill {--from= : First date to score, YYYY-MM-DD} {--to= : Last date to score, YYYY-MM-DD}')]
#[Description('Queue score jobs for a date range.')]
class ScoreBackfillCommand extends Command
{
    public function handle(): int
    {
        $from = CarbonImmutable::parse($this->option('from') ?: today()->subDays(7))->startOfDay();
        $to = CarbonImmutable::parse($this->option('to') ?: today())->startOfDay();

        if ($from->gt($to)) {
            $this->error('The --from date must be before or equal to the --to date.');

            return self::FAILURE;
        }

        $ruleIds = AlertRule::query()->where('is_enabled', true)->pluck('id');
        $queued = 0;

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $dateString = $date->toDateString();
            $scoreRun = ScoreRun::query()->firstOrCreate(['run_date' => $dateString], ['status' => ScoreRunStatus::Pending, 'started_at' => now()]);

            $ruleIds->each(function (int $ruleId) use ($dateString, $scoreRun, &$queued): void {
                ComputeScoreForRuleJob::dispatch($ruleId, $dateString, $scoreRun->id);
                $queued++;
            });
        }

        $this->info("Queued {$queued} score job(s) from {$from->toDateString()} to {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
