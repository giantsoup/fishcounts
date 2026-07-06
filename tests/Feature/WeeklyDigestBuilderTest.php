<?php

namespace Tests\Feature;

use App\Enums\BookingProvider;
use App\Enums\ScoreLevel;
use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\EnvironmentalDailySummary;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\Region;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use App\Services\Notifications\WeeklyDigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeeklyDigestBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_digest_includes_trend_best_day_ranked_trip_options_and_recommendations(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pointloma.fishingreservations.net/*' => Http::response('<html><body><table></table></body></html>', 200),
        ]);

        $user = User::factory()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create([
            'region_id' => $region->id,
            'name' => 'Point Loma Sportfishing',
            'slug' => 'point-loma',
            'website_url' => 'https://landing.example.test/point-loma',
            'booking_provider' => BookingProvider::FishingReservations,
            'booking_base_url' => 'https://pointloma.fishingreservations.net/sales/',
        ]);
        $topBoat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'New Lo-An',
            'slug' => 'new-lo-an',
            'booking_url' => 'https://booking.example.test/new-lo-an',
        ]);
        $secondBoat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Mission Belle',
            'slug' => 'mission-belle',
            'booking_provider_identifier' => '214',
        ]);
        $sourceFallbackLanding = Landing::query()->create([
            'region_id' => $region->id,
            'name' => 'No Website Landing',
            'slug' => 'no-website-landing',
        ]);
        $sourceFallbackBoat = Boat::query()->create([
            'landing_id' => $sourceFallbackLanding->id,
            'name' => 'Searcher',
            'slug' => 'searcher',
        ]);
        $filteredBoat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Daily Double', 'slug' => 'daily-double']);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $otherSpecies = Species::query()->create(['name' => 'Calico Bass', 'slug' => 'calico-bass']);
        $tripType = TripType::query()->create(['name' => '3/4 Day', 'slug' => '3-4-day']);
        $filteredTripType = TripType::query()->create(['name' => '1/2 Day', 'slug' => '1-2-day']);
        $source = ScrapeSource::query()->create([
            'name' => 'Point Loma Sportfishing',
            'slug' => 'point_loma_sportfishing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.pointlomasportfishing.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-06-17',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-17',
            'url' => 'https://www.pointlomasportfishing.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        $sportfishingReportSource = ScrapeSource::query()->create([
            'name' => 'SportfishingReport',
            'slug' => 'sportfishingreport',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.sportfishingreport.com',
        ]);
        $sportfishingReportScrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $sportfishingReportSource->id,
            'run_type' => 'daily',
            'target_date' => '2026-06-17',
            'status' => 'succeeded',
        ]);
        $sportfishingReportPayload = RawScrapePayload::query()->create([
            'scrape_run_id' => $sportfishingReportScrapeRun->id,
            'scrape_source_id' => $sportfishingReportSource->id,
            'target_date' => '2026-06-17',
            'url' => 'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-17',
            'http_status' => 200,
            'payload' => 'sportfishing-report-fixture',
            'payload_hash' => hash('sha256', 'sportfishing-report-fixture'),
            'fetched_at' => now(),
        ]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'name' => 'Local Yellowtail',
            'species_id' => $species->id,
            'include_in_weekly_digest' => true,
        ]);
        $rule->regions()->sync([$region->id]);
        $rule->tripTypes()->sync([$tripType->id]);

        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-17']);
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-11',
            'score' => 55,
            'level' => ScoreLevel::Cold,
            'total_count' => 10,
            'boat_count' => 1,
            'landing_count' => 1,
            'explanation' => [],
        ]);
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-06-17',
            'moon_phase' => 'New Moon',
            'water_temp_f_avg' => 67.8,
            'swell_direction_degrees_dominant' => 210,
            'condition_summary' => 'moon New Moon; water 67.8 F; swell 2.1 ft @ 11s SSW.',
            'coverage' => [],
            'is_partial' => false,
        ]);
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-17',
            'score' => 82,
            'level' => ScoreLevel::Hot,
            'total_count' => 84,
            'total_anglers' => 135,
            'count_per_angler' => 0.62,
            'boat_count' => 1,
            'landing_count' => 1,
            'explanation' => [],
        ]);

        $topTrip = $this->tripReport($sportfishingReportSource, $sportfishingReportPayload, $region, $landing, $topBoat, $tripType, '2026-06-17', 24, 'top-trip');
        SpeciesCount::query()->create([
            'trip_report_id' => $topTrip->id,
            'species_id' => $species->id,
            'count' => 60,
            'raw_species_name' => 'Yellowtail',
            'raw_count_text' => '60 Yellowtail, 12 Bonito',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $topTrip->id,
            'species_id' => $otherSpecies->id,
            'count' => 12,
            'raw_species_name' => 'Calico Bass',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $topTrip->id,
            'species_id' => $otherSpecies->id,
            'count' => 50,
            'is_retained_count' => false,
            'raw_species_name' => 'Released Calico Bass',
        ]);

        $secondTrip = $this->tripReport($source, $payload, $region, $landing, $secondBoat, $tripType, '2026-06-17', null, 'second-trip');
        SpeciesCount::query()->create([
            'trip_report_id' => $secondTrip->id,
            'species_id' => $species->id,
            'count' => 40,
            'raw_species_name' => 'Yellowtail',
        ]);

        $sourceFallbackTrip = $this->tripReport($source, $payload, $region, $sourceFallbackLanding, $sourceFallbackBoat, $tripType, '2026-06-16', 18, 'source-fallback-trip');
        SpeciesCount::query()->create([
            'trip_report_id' => $sourceFallbackTrip->id,
            'species_id' => $species->id,
            'count' => 25,
            'raw_species_name' => 'Yellowtail',
        ]);

        $filteredTrip = $this->tripReport($source, $payload, $region, $landing, $filteredBoat, $filteredTripType, '2026-06-17', 20, 'filtered-trip');
        SpeciesCount::query()->create([
            'trip_report_id' => $filteredTrip->id,
            'species_id' => $species->id,
            'count' => 99,
            'raw_species_name' => 'Yellowtail',
        ]);

        $summary = app(WeeklyDigestBuilder::class)->summaries($user, CarbonImmutable::parse('2026-06-17'))->first();
        $tripOptions = $summary['trip_options'];
        $tripRecommendations = $summary['trip_recommendations'];
        $content = app(WeeklyDigestBuilder::class)->discordContent($user, CarbonImmutable::parse('2026-06-17'));

        $this->assertSame('New Lo-An', $tripOptions[0]['boat_name']);
        $this->assertSame(60, $tripOptions[0]['target_count']);
        $this->assertSame('https://booking.example.test/new-lo-an', $tripOptions[0]['booking_url']);
        $this->assertSame('https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-17', $tripOptions[0]['source_url']);
        $this->assertSame('https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-17#:~:text=60%20Yellowtail', $tripOptions[0]['source_highlight_url']);
        $this->assertArrayNotHasKey('target_per_angler', $tripOptions[0]);
        $this->assertArrayNotHasKey('other_species_summary', $tripOptions[0]);
        $this->assertArrayNotHasKey('counts_url', $tripOptions[0]);
        $this->assertSame('Mission Belle', $tripOptions[1]['boat_name']);
        $this->assertSame('https://www.pointlomasportfishing.com/fishcounts.php#:~:text=Mission%20Belle', $tripOptions[1]['source_highlight_url']);
        $this->assertSame('https://pointloma.fishingreservations.net/sales/?boat_filter%5B0%5D=214', $tripOptions[1]['booking_url']);
        $this->assertSame('Searcher', $tripOptions[2]['boat_name']);
        $this->assertSame('https://www.pointlomasportfishing.com/fishcounts.php', $tripOptions[2]['booking_url']);
        $this->assertCount(3, $tripRecommendations);
        $this->assertSame(['New Lo-An', 'Mission Belle', 'Searcher'], $tripRecommendations->pluck('boat_name')->all());
        $this->assertFalse($tripOptions->contains(fn (array $trip): bool => $trip['boat_name'] === 'Daily Double'));
        $this->assertStringContainsString('Local Yellowtail', $content);
        $this->assertStringContainsString('best 6/17/2026 (82)', $content);
        $this->assertStringContainsString('Weekly conditions: moon New Moon; avg water 67.8 F; dominant swell SSW.', $content);
        $this->assertStringContainsString('conditions moon New Moon; water 67.8 F; swell 2.1 ft @ 11s SSW.', $content);
        $this->assertStringContainsString('trend +27', $content);
        $this->assertStringContainsString('ranked trips: New Lo-An 3/4 Day 6/17/26 60 target, Mission Belle 3/4 Day 6/17/26 40 target, Searcher 3/4 Day 6/16/26 25 target', $content);
        $this->assertStringContainsString('recommended: New Lo-An 3/4 Day 6/17/26 https://booking.example.test/new-lo-an, Mission Belle 3/4 Day 6/17/26 https://pointloma.fishingreservations.net/sales/?boat_filter%5B0%5D=214, Searcher 3/4 Day 6/16/26 https://www.pointlomasportfishing.com/fishcounts.php', $content);
        $this->assertStringNotContainsString('/angler', $content);
    }

    private function tripReport(
        ScrapeSource $source,
        RawScrapePayload $payload,
        Region $region,
        Landing $landing,
        Boat $boat,
        TripType $tripType,
        string $date,
        ?int $anglers,
        string $dedupeKey,
    ): TripReport {
        return TripReport::query()->create([
            'source_id' => $source->id,
            'raw_scrape_payload_id' => $payload->id,
            'region_id' => $region->id,
            'landing_id' => $landing->id,
            'boat_id' => $boat->id,
            'trip_type_id' => $tripType->id,
            'trip_date' => $date,
            'source_trip_identifier' => $dedupeKey,
            'anglers' => $anglers,
            'raw_boat_name' => $boat->name,
            'raw_landing_name' => $landing->name,
            'raw_trip_type' => $tripType->name,
            'dedupe_key' => $dedupeKey,
        ]);
    }
}
