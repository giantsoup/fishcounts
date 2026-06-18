<?php

namespace App\Services\Scraping\Adapters;

use Carbon\CarbonImmutable;

class PointLomaSportfishingAdapter extends AbstractHttpFishCountAdapter
{
    public function sourceKey(): string
    {
        return 'point_loma_sportfishing';
    }

    protected function pathForDate(CarbonImmutable $date): string
    {
        return '/fishcounts.php';
    }
}
