<?php

namespace App\Console\Commands;

use App\Enums\ScoreRunStatus;
use App\Jobs\ComputeScoreForRuleJob;
use App\Models\AlertRule;
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
        $date = today()->toDateString();
        $scoreRun = ScoreRun::query()->firstOrCreate(['run_date' => $date], ['status' => ScoreRunStatus::Pending, 'started_at' => now()]);

        AlertRule::query()
            ->where('is_enabled', true)
            ->pluck('id')
            ->each(fn (int $ruleId): mixed => ComputeScoreForRuleJob::dispatch($ruleId, $date, $scoreRun->id));

        $this->info("Score jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
