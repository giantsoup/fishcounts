<?php

namespace App\Services\Scraping\Adapters;

use Carbon\CarbonImmutable;

class SanDiegoFishReportsAdapter extends AbstractHttpFishCountAdapter
{
    public function sourceKey(): string
    {
        return 'sandiego_fish_reports';
    }

    protected function pathForDate(CarbonImmutable $date): string
    {
        return '/counts.php?date='.$date->format('Y-m-d');
    }
}
