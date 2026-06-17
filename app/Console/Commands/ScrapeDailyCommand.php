<?php

namespace App\Console\Commands;

use App\Enums\ScrapeRunStatus;
use App\Enums\ScrapeRunType;
use App\Models\ScrapeRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:scrape-daily')]
#[Description('Create the daily scrape run envelope for enabled sources.')]
class ScrapeDailyCommand extends Command
{
    public function handle(): int
    {
        ScrapeRun::query()->firstOrCreate(
            ['run_type' => ScrapeRunType::Daily, 'target_date' => today()],
            ['status' => ScrapeRunStatus::Pending],
        );

        $this->info('Daily scrape run recorded.');

        return self::SUCCESS;
    }
}
