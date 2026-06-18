<?php

namespace Tests\Feature;

use App\Enums\ScoreLevel;
use App\Enums\ScoreRunStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountsAndScoresIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_index_filters_counts_and_excludes_non_primary_reports(): void
    {
        $user = User::factory()->create();
        $context = $this->countContext();
        $yellowtail = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $rockfish = Species::query()->create(['name' => 'Rockfish', 'slug' => 'rockfish']);

        $primaryReport = $this->tripReport($context, '2026-06-15', true, 20);
        SpeciesCount::query()->create([
            'trip_report_id' => $primaryReport->id,
            'species_id' => $yellowtail->id,
            'count' => 30,
            'released_count' => 5,
        ]);

        $otherSpeciesReport = $this->tripReport($context, '2026-06-15', true, 10);
        SpeciesCount::query()->create([
            'trip_report_id' => $otherSpeciesReport->id,
            'species_id' => $rockfish->id,
            'count' => 100,
        ]);

        $duplicateReport = $this->tripReport($context, '2026-06-15', false, 20);
        SpeciesCount::query()->create([
            'trip_report_id' => $duplicateReport->id,
            'species_id' => $yellowtail->id,
            'count' => 999,
        ]);

        $response = $this->actingAs($user)->get(route('counts.index', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'species_id' => $yellowtail->id,
            'trip_type_id' => $context['tripType']->id,
            'landing_id' => $context['landing']->id,
            'boat_id' => $context['boat']->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('Yellowtail')
            ->assertSee('30')
            ->assertSee('5')
            ->assertDontSeeText('100')
            ->assertDontSeeText('999');
    }

    public function test_counts_pagination_preserves_filters(): void
    {
        $user = User::factory()->create();
        $context = $this->countContext();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);

        foreach (range(1, 55) as $index) {
            $report = $this->tripReport($context, '2026-06-15', true, 20, "report-{$index}");
            SpeciesCount::query()->create([
                'trip_report_id' => $report->id,
                'species_id' => $species->id,
                'count' => $index,
            ]);
        }

        $this->actingAs($user)
            ->get(route('counts.index', [
                'from' => '2026-06-01',
                'to' => '2026-06-30',
                'species_id' => $species->id,
            ]))
            ->assertOk()
            ->assertSee('species_id='.$species->id, false);
    }

    public function test_scores_index_filters_scores_and_only_shows_current_users_rules(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-15', 'status' => ScoreRunStatus::Succeeded]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'species_id' => $species->id,
            'name' => 'Local Yellowtail',
            'minimum_score' => 70,
        ]);
        $otherRule = AlertRule::query()->create([
            'user_id' => $otherUser->id,
            'species_id' => $species->id,
            'name' => 'Other User Rule',
            'minimum_score' => 70,
        ]);

        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-15',
            'score' => 82,
            'level' => ScoreLevel::Hot,
            'total_count' => 100,
            'boat_count' => 2,
            'landing_count' => 1,
            'trend_score' => 80,
            'count_score' => 100,
            'count_per_angler_score' => 70,
            'breadth_score' => 60,
            'source_confidence_score' => 90,
            'explanation' => ['test' => true],
        ]);
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $otherRule->id,
            'score_date' => '2026-06-15',
            'score' => 99,
            'level' => ScoreLevel::WideOpen,
            'total_count' => 999,
            'boat_count' => 9,
            'landing_count' => 9,
            'trend_score' => 99,
            'count_score' => 99,
            'count_per_angler_score' => 99,
            'breadth_score' => 99,
            'source_confidence_score' => 99,
            'explanation' => ['test' => true],
        ]);

        $this->actingAs($user)
            ->get(route('scores.index', [
                'from' => '2026-06-01',
                'to' => '2026-06-30',
                'alert_rule_id' => $rule->id,
                'level' => ScoreLevel::Hot->value,
                'minimum_score' => 80,
            ]))
            ->assertOk()
            ->assertSee('Local Yellowtail')
            ->assertSee('82')
            ->assertDontSee('Other User Rule')
            ->assertDontSee('999');
    }

    public function test_scores_rejects_other_users_rule_filter(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $otherRule = AlertRule::query()->create([
            'user_id' => $otherUser->id,
            'species_id' => $species->id,
            'name' => 'Other User Rule',
            'minimum_score' => 70,
        ]);

        $this->actingAs($user)
            ->get(route('scores.index', ['alert_rule_id' => $otherRule->id]))
            ->assertSessionHasErrors('alert_rule_id');
    }

    /** @return array{region: Region, landing: Landing, boat: Boat, tripType: TripType, source: ScrapeSource} */
    private function countContext(): array
    {
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
        $boat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Dolphin', 'slug' => 'dolphin']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
            'priority' => 10,
        ]);

        return compact('region', 'landing', 'boat', 'tripType', 'source');
    }

    /** @param array{region: Region, landing: Landing, boat: Boat, tripType: TripType, source: ScrapeSource} $context */
    private function tripReport(array $context, string $date, bool $isPrimary, int $anglers, ?string $identifier = null): TripReport
    {
        $identifier ??= uniqid('report-', true);

        return TripReport::query()->create([
            'source_id' => $context['source']->id,
            'region_id' => $context['region']->id,
            'landing_id' => $context['landing']->id,
            'boat_id' => $context['boat']->id,
            'trip_type_id' => $context['tripType']->id,
            'trip_date' => $date,
            'source_trip_identifier' => $identifier,
            'anglers' => $anglers,
            'raw_boat_name' => $context['boat']->name,
            'raw_landing_name' => $context['landing']->name,
            'raw_trip_type' => $context['tripType']->name,
            'is_deduped_primary' => $isPrimary,
            'dedupe_key' => $identifier,
            'source_confidence' => 90,
        ]);
    }
}
