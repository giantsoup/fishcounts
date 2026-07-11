<?php

namespace App\Services\Environmental;

use App\Jobs\BackfillEnvironmentalSourceForDateJob;
use App\Jobs\FinalizeEnvironmentalConditionsForDateJob;
use App\Models\EnvironmentalSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

class EnvironmentalBackfillDispatcher
{
    public const string EARLIEST_DATE = '2026-01-01';

    public function dispatchRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $locationProfile = null,
        bool $synchronously = false,
    ): int {
        $this->assertValidRange($from, $to);

        $sourcesByProfile = EnvironmentalSource::query()
            ->where('is_enabled', true)
            ->where('supports_historical_dates', true)
            ->when($locationProfile !== null && $locationProfile !== '', fn ($query) => $query->where('location_profile', $locationProfile))
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'location_profile'])
            ->groupBy('location_profile');
        $collectionCount = 0;

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            foreach ($sourcesByProfile as $profile => $sources) {
                $jobs = $sources
                    ->map(fn (EnvironmentalSource $source): BackfillEnvironmentalSourceForDateJob => new BackfillEnvironmentalSourceForDateJob(
                        $source->id,
                        $date->toDateString(),
                    ))
                    ->push(new FinalizeEnvironmentalConditionsForDateJob($profile, $date->toDateString()))
                    ->all();

                if ($synchronously) {
                    foreach ($jobs as $job) {
                        Bus::dispatchSync($job);
                    }
                } else {
                    Bus::chain($jobs)->dispatch();
                }

                $collectionCount += $sources->count();
            }
        }

        return $collectionCount;
    }

    public static function earliestDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::EARLIEST_DATE, self::timezone())->startOfDay();
    }

    public static function latestDate(): CarbonImmutable
    {
        return CarbonImmutable::today(self::timezone());
    }

    private function assertValidRange(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->toDateString() < self::earliestDate()->toDateString()) {
            throw new InvalidArgumentException('Environmental backfills cannot start before '.self::EARLIEST_DATE.'.');
        }

        if ($from->toDateString() > $to->toDateString()) {
            throw new InvalidArgumentException('The environmental backfill start date must not be after its end date.');
        }

        if ($to->toDateString() > self::latestDate()->toDateString()) {
            throw new InvalidArgumentException('Environmental backfills cannot include future dates.');
        }
    }

    private static function timezone(): string
    {
        return (string) config('fish.conditions.timezone', 'America/Los_Angeles');
    }
}
