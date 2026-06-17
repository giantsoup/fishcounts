<?php

namespace App\Services\Scraping\Adapters;

use Carbon\CarbonImmutable;

class Tuna976ReportsAdapter extends AbstractHttpFishCountAdapter
{
    public function sourceKey(): string
    {
        return 'tuna_976_reports';
    }

    protected function pathForDate(CarbonImmutable $date): string
    {
        return '/fish-counts.php?date='.$date->format('Y-m-d');
    }
}
