<?php

namespace App\Jobs;

use App\Models\AlertRule;
use App\Models\ScoreRun;
use App\Services\Scoring\HotBiteScoringService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class ComputeScoreForRuleJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $alertRuleId, public string $date, public int $scoreRunId)
    {
        $this->onQueue('scoring');
    }

    public function uniqueId(): string
    {
        return "{$this->alertRuleId}:{$this->date}";
    }

    public function handle(HotBiteScoringService $scoringService): void
    {
        $scoringService->score(
            AlertRule::query()->findOrFail($this->alertRuleId),
            CarbonImmutable::parse($this->date),
            ScoreRun::query()->findOrFail($this->scoreRunId),
        );
    }

    public function failed(Throwable $throwable): void
    {
        report($throwable);
    }
}
