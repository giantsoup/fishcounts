<?php

namespace App\Jobs;

use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalSource;
use App\Services\Environmental\EnvironmentalDailySummaryBuilder;
use App\Services\Environmental\EnvironmentalPayloadStore;
use App\Services\Environmental\EnvironmentalSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class CollectEnvironmentalSourceForDateJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $environmentalSourceId,
        public string $date,
        public bool $finalize = false,
    ) {
        $this->onQueue('environmental');
    }

    public function uniqueId(): string
    {
        return "{$this->environmentalSourceId}:{$this->date}:".($this->finalize ? 'final' : 'partial');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        EnvironmentalSourceRegistry $registry,
        EnvironmentalPayloadStore $payloadStore,
        EnvironmentalDailySummaryBuilder $summaryBuilder,
    ): void {
        $source = EnvironmentalSource::query()->findOrFail($this->environmentalSourceId);
        $date = CarbonImmutable::parse($this->date, (string) config('fish.conditions.timezone', 'America/Los_Angeles'))->startOfDay();

        if (! $source->is_enabled) {
            return;
        }

        try {
            $adapter = $registry->forSource($source);
            $result = $adapter->fetchForDate($source, $date);
            $payload = $payloadStore->store($source, $date, $result);
            $observations = $adapter->observations($source, $payload);

            EnvironmentalObservation::query()
                ->where('environmental_source_id', $source->id)
                ->where('location_profile', $source->location_profile)
                ->where('location_type', $source->location_type->value)
                ->whereDate('observed_date', $date->toDateString())
                ->delete();

            foreach ($observations as $observation) {
                EnvironmentalObservation::query()->create($observation);
            }

            $summaryBuilder->recompute($source->location_profile, $date, $this->finalize);
            $source->update(['last_success_at' => now()]);
        } catch (Throwable $throwable) {
            $source->update(['last_failure_at' => now()]);

            throw $throwable;
        }
    }

    public function failed(Throwable $throwable): void
    {
        EnvironmentalSource::query()->whereKey($this->environmentalSourceId)->update(['last_failure_at' => now()]);

        report($throwable);
    }
}
