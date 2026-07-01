<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\Region;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use App\Services\Scoring\HotBiteScoringService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotBiteScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $tripReportSequence = 0;

    public function test_scoring_uses_rule_filters_and_metrics(): void
    {
        $context = $this->scoringContext();

        $this->createTripReport($context, '2026-01-05', 100, 20);
        $score = $this->scoreRule($context['rule'], '2026-01-05');

        $this->assertSame(100, $score->total_count);
        $this->assertSame(20, $score->total_anglers);
        $this->assertSame('5.00', $score->count_per_angler);
        $this->assertGreaterThanOrEqual(70, $score->score);
    }

    public function test_trend_score_compares_recent_average_to_prior_comparison_average(): void
    {
        $context = $this->scoringContext(recentWindowDays: 3, comparisonWindowDays: 7);

        $this->createTripReport($context, '2026-06-22', 35);
        $this->createTripReport($context, '2026-06-28', 35);
        $this->createTripReport($context, '2026-06-29', 15);
        $this->createTripReport($context, '2026-07-01', 30);

        $score = $this->scoreRule($context['rule'], '2026-07-01');

        $this->assertSame(75, $score->trend_score);
        $this->assertEquals(15.0, $score->explanation['recent_average_total_count']);
        $this->assertEquals(10.0, $score->explanation['comparison_average_total_count']);
        $this->assertSame('2026-06-29', $score->explanation['recent_window_start']);
        $this->assertSame('2026-07-01', $score->explanation['recent_window_end']);
        $this->assertSame('2026-06-22', $score->explanation['comparison_window_start']);
        $this->assertSame('2026-06-28', $score->explanation['comparison_window_end']);
    }

    public function test_trend_score_counts_missing_window_days_as_zero(): void
    {
        $context = $this->scoringContext(recentWindowDays: 3, comparisonWindowDays: 7);

        $this->createTripReport($context, '2026-06-28', 70);
        $this->createTripReport($context, '2026-07-01', 30);

        $score = $this->scoreRule($context['rule'], '2026-07-01');

        $this->assertSame(50, $score->trend_score);
        $this->assertEquals(10.0, $score->explanation['recent_average_total_count']);
        $this->assertEquals(10.0, $score->explanation['comparison_average_total_count']);
    }

    public function test_trend_score_returns_eighty_for_recent_catch_with_no_comparison_catch(): void
    {
        $context = $this->scoringContext(recentWindowDays: 3, comparisonWindowDays: 7);

        $this->createTripReport($context, '2026-07-01', 30);

        $score = $this->scoreRule($context['rule'], '2026-07-01');

        $this->assertSame(80, $score->trend_score);
        $this->assertEquals(10.0, $score->explanation['recent_average_total_count']);
        $this->assertEquals(0.0, $score->explanation['comparison_average_total_count']);
    }

    public function test_score_date_metrics_do_not_use_recent_window_totals(): void
    {
        $context = $this->scoringContext(recentWindowDays: 3, comparisonWindowDays: 7);

        $this->createTripReport($context, '2026-06-29', 100, 10);
        $this->createTripReport($context, '2026-06-30', 100, 10);
        $this->createTripReport($context, '2026-07-01', 20, 5);

        $score = $this->scoreRule($context['rule'], '2026-07-01');

        $this->assertSame(20, $score->total_count);
        $this->assertSame(5, $score->total_anglers);
        $this->assertSame('4.00', $score->count_per_angler);
        $this->assertSame(73.33, $score->explanation['recent_average_total_count']);
    }

    /** @return array{rule: AlertRule, region: Region, landing: Landing, boat: Boat, tripType: TripType, species: Species, source: ScrapeSource} */
    private function scoringContext(int $recentWindowDays = 3, int $comparisonWindowDays = 7): array
    {
        $user = User::factory()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
        $boat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Dolphin', 'slug' => 'dolphin']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $source = ScrapeSource::query()->create([
            'name' => 'Source',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
            'priority' => 10,
        ]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'species_id' => $species->id,
            'name' => 'Local Yellowtail',
            'minimum_score' => 70,
            'recent_window_days' => $recentWindowDays,
            'comparison_window_days' => $comparisonWindowDays,
        ]);
        $rule->regions()->attach($region);
        $rule->tripTypes()->attach($tripType);

        return [
            'rule' => $rule,
            'region' => $region,
            'landing' => $landing,
            'boat' => $boat,
            'tripType' => $tripType,
            'species' => $species,
            'source' => $source,
        ];
    }

    /** @param array{rule: AlertRule, region: Region, landing: Landing, boat: Boat, tripType: TripType, species: Species, source: ScrapeSource} $context */
    private function createTripReport(array $context, string $date, int $count, int $anglers = 10): TripReport
    {
        $this->tripReportSequence++;

        $report = TripReport::query()->create([
            'source_id' => $context['source']->id,
            'region_id' => $context['region']->id,
            'landing_id' => $context['landing']->id,
            'boat_id' => $context['boat']->id,
            'trip_type_id' => $context['tripType']->id,
            'trip_date' => $date,
            'source_trip_identifier' => 'source-'.$this->tripReportSequence,
            'anglers' => $anglers,
            'dedupe_key' => $date.'-dolphin-full-day-'.$this->tripReportSequence,
            'source_confidence' => 90,
        ]);

        SpeciesCount::query()->create([
            'trip_report_id' => $report->id,
            'species_id' => $context['species']->id,
            'count' => $count,
            'raw_species_name' => 'Yellowtail',
        ]);

        return $report;
    }

    private function scoreRule(AlertRule $rule, string $date): ScoreResult
    {
        $scoreRun = ScoreRun::query()->create(['run_date' => $date]);

        return app(HotBiteScoringService::class)->score($rule, CarbonImmutable::parse($date), $scoreRun);
    }
}
