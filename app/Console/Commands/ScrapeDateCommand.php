<?php

namespace App\Console\Commands;

use App\Enums\ScrapeRunType;
use App\Jobs\ScrapeSourceForDateJob;
use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:scrape-date {date}')]
#[Description('Create a manual scrape run envelope for a specific date.')]
class ScrapeDateCommand extends Command
{
    public function handle(): int
    {
        $date = CarbonImmutable::parse($this->argument('date'))->toDateString();

        ScrapeSource::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->pluck('id')
            ->each(fn (int $sourceId): mixed => ScrapeSourceForDateJob::dispatch($sourceId, $date, ScrapeRunType::Manual));

        $this->info("Manual scrape jobs queued for {$date}.");

        return self::SUCCESS;
    }
}
