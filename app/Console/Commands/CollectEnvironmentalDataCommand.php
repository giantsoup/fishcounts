<?php

namespace App\Console\Commands;

use App\Jobs\CollectEnvironmentalSourceForDateJob;
use App\Models\EnvironmentalSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:collect-environmental-data {date?} {--finalize}')]
#[Description('Queue environmental condition collection for a date.')]
class CollectEnvironmentalDataCommand extends Command
{
    public function handle(): int
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $locationProfile = (string) config('fish.conditions.location_profile', 'san_diego_bight');
        $date = CarbonImmutable::parse($this->argument('date') ?: today($timezone), $timezone)->toDateString();
        $finalize = (bool) $this->option('finalize');

        EnvironmentalSource::query()
            ->where('is_enabled', true)
            ->where('location_profile', $locationProfile)
            ->orderBy('priority')
            ->pluck('id')
            ->each(fn (int $sourceId): mixed => CollectEnvironmentalSourceForDateJob::dispatch($sourceId, $date, $finalize));

        $mode = $finalize ? 'finalized' : 'partial';
        $this->info("Environmental {$mode} collection jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
