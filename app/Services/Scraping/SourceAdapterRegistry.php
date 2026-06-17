<?php

namespace App\Services\Scraping;

use App\Models\ScrapeSource;
use App\Services\Scraping\Adapters\FishermansLandingAdapter;
use App\Services\Scraping\Adapters\HmLandingAdapter;
use App\Services\Scraping\Adapters\PointLomaSportfishingAdapter;
use App\Services\Scraping\Adapters\SanDiegoFishReportsAdapter;
use App\Services\Scraping\Adapters\SeaforthLandingAdapter;
use App\Services\Scraping\Adapters\SportfishingReportLandingPagesAdapter;
use App\Services\Scraping\Adapters\Tuna976ReportsAdapter;
use App\Services\Scraping\Contracts\FishCountSourceAdapter;
use InvalidArgumentException;

class SourceAdapterRegistry
{
    /** @var array<string, class-string<FishCountSourceAdapter>> */
    private array $adapters = [
        'sandiego_fish_reports' => SanDiegoFishReportsAdapter::class,
        'fishermans_landing' => FishermansLandingAdapter::class,
        'seaforth_landing' => SeaforthLandingAdapter::class,
        'hm_landing' => HmLandingAdapter::class,
        'point_loma_sportfishing' => PointLomaSportfishingAdapter::class,
        'sportfishingreport_landing_pages' => SportfishingReportLandingPagesAdapter::class,
        'tuna_976_reports' => Tuna976ReportsAdapter::class,
    ];

    public function forSource(ScrapeSource $source): FishCountSourceAdapter
    {
        $adapterClass = $this->adapters[$source->slug] ?? null;

        if ($adapterClass === null) {
            throw new InvalidArgumentException("No scraper adapter registered for source [{$source->slug}].");
        }

        return app($adapterClass);
    }
}
