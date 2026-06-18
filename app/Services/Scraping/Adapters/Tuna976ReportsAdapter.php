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
        return '/counts?m='.$date->month.'&d='.$date->day.'&y='.$date->year;
    }
}
