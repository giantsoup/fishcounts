<?php

namespace App\Console\Commands;

use App\Enums\ScrapeRunType;
use App\Jobs\ScrapeSourceForDateJob;
use App\Models\ScrapeSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:scrape-daily')]
#[Description('Create the daily scrape run envelope for enabled sources.')]
class ScrapeDailyCommand extends Command
{
    public function handle(): int
    {
        $date = today()->toDateString();

        ScrapeSource::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->pluck('id')
            ->each(fn (int $sourceId): mixed => ScrapeSourceForDateJob::dispatch($sourceId, $date, ScrapeRunType::Daily));

        $this->info("Daily scrape jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
