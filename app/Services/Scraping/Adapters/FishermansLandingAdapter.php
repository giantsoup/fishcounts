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
        return '/fishcounts.php';
    }
}
