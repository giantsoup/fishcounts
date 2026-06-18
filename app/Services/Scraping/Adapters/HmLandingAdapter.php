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
        return 'https://www.fishcounts.com/hmlanding/fishcounts.php';
    }
}
