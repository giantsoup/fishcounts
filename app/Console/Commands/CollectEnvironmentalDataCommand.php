<?php

namespace App\Console\Commands;

use App\Jobs\CollectEnvironmentalSourceForDateJob;
use App\Models\EnvironmentalSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:collect-environmental-data {date?} {--finalize} {--profile= : Limit collection to one environmental location profile.}')]
#[Description('Queue environmental condition collection for a date.')]
class CollectEnvironmentalDataCommand extends Command
{
    public function handle(): int
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $locationProfile = $this->option('profile');
        $date = CarbonImmutable::parse($this->argument('date') ?: today($timezone), $timezone)->toDateString();
        $finalize = (bool) $this->option('finalize');

        $sourceIds = EnvironmentalSource::query()
            ->where('is_enabled', true)
            ->when(is_string($locationProfile) && $locationProfile !== '', fn ($query) => $query->where('location_profile', $locationProfile))
            ->orderBy('priority')
            ->pluck('id');

        $sourceIds->each(fn (int $sourceId): mixed => CollectEnvironmentalSourceForDateJob::dispatch($sourceId, $date, $finalize));

        $mode = $finalize ? 'finalized' : 'partial';
        $scope = is_string($locationProfile) && $locationProfile !== '' ? " for {$locationProfile}" : '';
        $this->info("Environmental {$mode} collection jobs queued for {$date}{$scope}: {$sourceIds->count()} sources.");

        return self::SUCCESS;
    }
}
