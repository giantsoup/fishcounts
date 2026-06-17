<?php

namespace App\Services\Scraping\Adapters;

use Carbon\CarbonImmutable;

class FishermansLandingAdapter extends AbstractHttpFishCountAdapter
{
    public function sourceKey(): string
    {
        return 'fishermans_landing';
    }

    protected function pathForDate(CarbonImmutable $date): string
    {
        return '/fish-counts.php?date='.$date->format('Y-m-d');
    }
}
