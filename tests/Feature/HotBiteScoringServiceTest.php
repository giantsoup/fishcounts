<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\Region;
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

    public function test_scoring_uses_rule_filters_and_metrics(): void
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
        ]);
        $rule->regions()->attach($region);
        $rule->tripTypes()->attach($tripType);

        $report = TripReport::query()->create([
            'source_id' => $source->id,
            'region_id' => $region->id,
            'landing_id' => $landing->id,
            'boat_id' => $boat->id,
            'trip_type_id' => $tripType->id,
            'trip_date' => '2026-01-05',
            'source_trip_identifier' => 'source-1',
            'anglers' => 20,
            'dedupe_key' => '2026-01-05-dolphin-full-day-20',
            'source_confidence' => 90,
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $report->id,
            'species_id' => $species->id,
            'count' => 100,
            'raw_species_name' => 'Yellowtail',
        ]);

        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-01-05']);
        $score = app(HotBiteScoringService::class)->score($rule, CarbonImmutable::parse('2026-01-05'), $scoreRun);

        $this->assertSame(100, $score->total_count);
        $this->assertSame(20, $score->total_anglers);
        $this->assertSame('5.00', $score->count_per_angler);
        $this->assertGreaterThanOrEqual(70, $score->score);
    }
}
