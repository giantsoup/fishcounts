<?php

namespace App\Jobs;

use App\Models\EnvironmentalSource;
use App\Services\Environmental\EnvironmentalSourceCollector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class BackfillEnvironmentalSourceForDateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public int $environmentalSourceId,
        public string $date,
    ) {
        $this->onQueue('environmental');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $locationProfile = EnvironmentalSource::query()
            ->whereKey($this->environmentalSourceId)
            ->value('location_profile') ?? "source-{$this->environmentalSourceId}";

        return [
            (new WithoutOverlapping("environmental-summary:{$locationProfile}:{$this->date}"))
                ->releaseAfter(60)
                ->expireAfter(180)
                ->shared(),
        ];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EnvironmentalSourceCollector $collector): void
    {
        $collector->collect($this->environmentalSourceId, $this->date);
    }

    public function failed(Throwable $throwable): void
    {
        EnvironmentalSource::query()->whereKey($this->environmentalSourceId)->update(['last_failure_at' => now()]);

        report($throwable);
    }
}
