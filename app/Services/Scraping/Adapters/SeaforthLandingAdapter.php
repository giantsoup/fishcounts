<?php

namespace App\Services\Scraping\Adapters;

use Carbon\CarbonImmutable;

class SeaforthLandingAdapter extends AbstractHttpFishCountAdapter
{
    public function sourceKey(): string
    {
        return 'seaforth_landing';
    }

    protected function pathForDate(CarbonImmutable $date): string
    {
        return '/fishcounts.php?date='.$date->format('Y-m-d');
    }
}
