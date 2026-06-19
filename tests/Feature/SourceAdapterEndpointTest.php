<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\ScrapeSource;
use App\Services\Scraping\Adapters\FishermansLandingAdapter;
use App\Services\Scraping\Adapters\HmLandingAdapter;
use App\Services\Scraping\Adapters\PointLomaSportfishingAdapter;
use App\Services\Scraping\Adapters\SanDiegoFishReportsAdapter;
use App\Services\Scraping\Adapters\SportfishingReportPartyBoatScoresAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceAdapterEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_adapter_urls_are_used_for_current_source_pages(): void
    {
        Http::fake([
            '*' => Http::response('<table></table>', 200),
        ]);

        $date = CarbonImmutable::parse('2026-06-17');

        app(FishermansLandingAdapter::class)->fetchForDate($this->source('fishermans_landing', 'https://www.fishermanslanding.com'), $date);
        app(PointLomaSportfishingAdapter::class)->fetchForDate($this->source('point_loma_sportfishing', 'https://www.pointlomasportfishing.com'), $date);
        app(SanDiegoFishReportsAdapter::class)->fetchForDate($this->source('sandiego_fish_reports', 'https://www.sandiegofishreports.com'), $date);
        app(HmLandingAdapter::class)->fetchForDate($this->source('hm_landing', 'https://www.hmlanding.com'), $date);
        app(SportfishingReportPartyBoatScoresAdapter::class)->fetchForDate($this->source('sportfishingreport_landing_pages', 'https://www.sportfishingreport.com'), $date);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.fishermanslanding.com/fishcounts.php');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.pointlomasportfishing.com/fishcounts.php');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.sandiegofishreports.com/dock_totals/index.php');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.fishcounts.com/hmlanding/fishcounts.php');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-17');
    }

    private function source(string $slug, string $baseUrl): ScrapeSource
    {
        return ScrapeSource::query()->create([
            'name' => str($slug)->replace('_', ' ')->title()->toString(),
            'slug' => $slug,
            'source_type' => SourceType::Landing,
            'base_url' => $baseUrl,
            'rate_limit_seconds' => 0,
        ]);
    }
}
