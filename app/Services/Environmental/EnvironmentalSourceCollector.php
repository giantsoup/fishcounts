<?php

namespace App\Services\Environmental;

use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class EnvironmentalSourceCollector
{
    public function __construct(
        private readonly EnvironmentalSourceRegistry $registry,
        private readonly EnvironmentalPayloadStore $payloadStore,
        private readonly EnvironmentalDailySummaryBuilder $summaryBuilder,
    ) {}

    public function collect(int $environmentalSourceId, string $dateString, bool $finalize = false): void
    {
        $source = EnvironmentalSource::query()->findOrFail($environmentalSourceId);
        $date = CarbonImmutable::parse(
            $dateString,
            (string) config('fish.conditions.timezone', 'America/Los_Angeles'),
        )->startOfDay();

        if (! $source->is_enabled) {
            return;
        }

        try {
            $adapter = $this->registry->forSource($source);
            $result = $adapter->fetchForDate($source, $date);
            $payload = $this->payloadStore->store($source, $date, $result);
            $observations = $adapter->observations($source, $payload);

            DB::transaction(function () use ($date, $finalize, $observations, $source): void {
                EnvironmentalObservation::query()
                    ->where('environmental_source_id', $source->id)
                    ->where('location_profile', $source->location_profile)
                    ->where('location_type', $source->location_type->value)
                    ->whereDate('observed_date', $date->toDateString())
                    ->delete();

                foreach ($observations as $observation) {
                    EnvironmentalObservation::query()->create($observation);
                }

                $this->summaryBuilder->recompute($source->location_profile, $date, $finalize);
                $source->update(['last_success_at' => now()]);
            });
        } catch (Throwable $throwable) {
            $source->update(['last_failure_at' => now()]);

            throw $throwable;
        }
    }
}
