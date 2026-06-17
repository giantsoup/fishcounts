<?php

namespace App\Services\Scraping\Adapters;

use Carbon\CarbonImmutable;

class HmLandingAdapter extends AbstractHttpFishCountAdapter
{
    public function sourceKey(): string
    {
        return 'hm_landing';
    }

    protected function pathForDate(CarbonImmutable $date): string
    {
        return '/fish-counts?date='.$date->format('Y-m-d');
    }
}
