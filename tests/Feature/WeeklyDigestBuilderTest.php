<?php

namespace Tests\Feature;

use App\Enums\ScoreLevel;
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
use App\Services\Notifications\WeeklyDigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyDigestBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_digest_includes_trend_best_day_top_boats_landings_and_data_quality(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Point Loma Sportfishing', 'slug' => 'point-loma']);
        $boat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Mission Belle', 'slug' => 'mission-belle']);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $tripType = TripType::query()->create(['name' => '3/4 Day', 'slug' => '3-4-day']);
        $source = ScrapeSource::query()->create([
            'name' => 'Point Loma Sportfishing',
            'slug' => 'point_loma_sportfishing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.pointlomasportfishing.com',
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

        $tripReport = TripReport::query()->create([
            'source_id' => $source->id,
            'region_id' => $region->id,
            'landing_id' => $landing->id,
            'boat_id' => $boat->id,
            'trip_type_id' => $tripType->id,
            'trip_date' => '2026-06-17',
            'source_trip_identifier' => 'mission-belle-2026-06-17',
            'anglers' => null,
            'raw_boat_name' => 'Mission Belle',
            'raw_landing_name' => 'Point Loma Sportfishing',
            'raw_trip_type' => '3/4 Day',
            'dedupe_key' => 'weekly-digest-sample',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $tripReport->id,
            'species_id' => $species->id,
            'count' => 84,
            'raw_species_name' => 'Yellowtail',
        ]);

        $content = app(WeeklyDigestBuilder::class)->discordContent($user, CarbonImmutable::parse('2026-06-17'));

        $this->assertStringContainsString('Local Yellowtail', $content);
        $this->assertStringContainsString('best 6/17/2026 (82)', $content);
        $this->assertStringContainsString('trend +27', $content);
        $this->assertStringContainsString('top boats: Mission Belle 84', $content);
        $this->assertStringContainsString('top landings: Point Loma Sportfishing 84', $content);
        $this->assertStringContainsString('data: 1 report(s) missing anglers', $content);
    }
}
