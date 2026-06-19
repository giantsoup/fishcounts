<?php

namespace App\Services\Scraping\Adapters;

use Carbon\CarbonImmutable;

class SportfishingReportPartyBoatScoresAdapter extends AbstractHttpFishCountAdapter
{
    public function sourceKey(): string
    {
        return 'sportfishingreport_landing_pages';
    }

    protected function pathForDate(CarbonImmutable $date): string
    {
        return '/dock_totals/boats.php?date='.$date->format('Y-m-d');
    }
}
