<?php

namespace App\Console\Commands;

use App\Enums\ScrapeRunStatus;
use App\Enums\ScrapeRunType;
use App\Models\ScrapeRun;
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

        ScrapeRun::query()->firstOrCreate(
            ['run_type' => ScrapeRunType::Manual, 'target_date' => $date],
            ['status' => ScrapeRunStatus::Pending],
        );

        $this->info("Manual scrape run recorded for {$date}.");

        return self::SUCCESS;
    }
}
