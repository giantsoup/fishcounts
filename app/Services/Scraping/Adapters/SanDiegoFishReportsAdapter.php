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
        return '/dock_totals/index.php';
    }
}
