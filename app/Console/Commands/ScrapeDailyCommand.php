<?php

namespace App\Console\Commands;

use App\Enums\ScrapeRunType;
use App\Jobs\ScrapeSourceForDateJob;
use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:scrape-daily {date?}')]
#[Description('Create scrape runs for enabled sources, defaulting to the previous completed day.')]
class ScrapeDailyCommand extends Command
{
    public function handle(): int
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $date = CarbonImmutable::parse(
            $this->argument('date') ?: CarbonImmutable::now($timezone)->subDay(),
            $timezone,
        )->toDateString();

        ScrapeSource::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->pluck('id')
            ->each(fn (int $sourceId): mixed => ScrapeSourceForDateJob::dispatch($sourceId, $date, ScrapeRunType::Daily));

        $this->info("Daily scrape jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
