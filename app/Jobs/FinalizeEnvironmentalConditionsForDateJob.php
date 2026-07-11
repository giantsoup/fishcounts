<?php

namespace App\Jobs;

use App\Services\Environmental\EnvironmentalDailySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class FinalizeEnvironmentalConditionsForDateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public string $locationProfile,
        public string $date,
    ) {
        $this->onQueue('environmental');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("environmental-summary:{$this->locationProfile}:{$this->date}"))
                ->releaseAfter(60)
                ->expireAfter(60)
                ->shared(),
        ];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EnvironmentalDailySummaryBuilder $summaryBuilder): void
    {
        $date = CarbonImmutable::parse(
            $this->date,
            (string) config('fish.conditions.timezone', 'America/Los_Angeles'),
        )->startOfDay();

        $summaryBuilder->recompute($this->locationProfile, $date, finalize: true);
    }

    public function failed(Throwable $throwable): void
    {
        report($throwable);
    }
}
