<?php

namespace Tests\Feature;

use App\Enums\AlertEventStatus;
use App\Enums\AlertEventType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\ScoreLevel;
use App\Enums\ScoreRunStatus;
use App\Enums\SourceType;
use App\Models\AlertEvent;
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
use App\Notifications\HotBiteAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CountsAndScoresIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_index_groups_species_counts_by_trip(): void
    {
        $user = User::factory()->create();
        $context = $this->countContext();
        $yellowtail = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $rockfish = Species::query()->create(['name' => 'Rockfish', 'slug' => 'rockfish']);
        $report = $this->tripReport($context, '2026-06-15', true, 20);

        SpeciesCount::query()->create([
            'trip_report_id' => $report->id,
            'species_id' => $yellowtail->id,
            'count' => 30,
            'released_count' => 5,
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $report->id,
            'species_id' => $rockfish->id,
            'count' => 12,
        ]);

        $this->actingAs($user)
            ->get(route('counts.index', [
                'from' => '2026-06-15',
                'to' => '2026-06-15',
            ]))
            ->assertOk()
            ->assertSee('Dolphin')
            ->assertSee('Fisherman&#039;s Landing', false)
            ->assertSee('30 Yellowtail')
            ->assertSee('5 Yellowtail Released')
            ->assertSee('12 Rockfish');
    }

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
        SpeciesCount::query()->create([
            'trip_report_id' => $primaryReport->id,
            'species_id' => $rockfish->id,
            'count' => 12,
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
            'from' => '06/01/2026',
            'to' => '06/30/2026',
            'species_id' => $yellowtail->id,
            'trip_type_id' => $context['tripType']->id,
            'landing_id' => $context['landing']->id,
            'boat_id' => $context['boat']->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('6/15/2026')
            ->assertSee('30 Yellowtail')
            ->assertSee('5 Yellowtail Released')
            ->assertDontSeeText('12 Rockfish')
            ->assertDontSeeText('100 Rockfish')
            ->assertDontSeeText('999 Yellowtail');
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

    public function test_counts_index_defaults_to_latest_available_count_date(): void
    {
        $user = User::factory()->create();
        $context = $this->countContext();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);

        $olderReport = $this->tripReport($context, '2026-06-15', true, 20, 'older-report');
        SpeciesCount::query()->create([
            'trip_report_id' => $olderReport->id,
            'species_id' => $species->id,
            'count' => 15,
        ]);

        $latestReport = $this->tripReport($context, '2026-07-05', true, 18, 'latest-report');
        SpeciesCount::query()->create([
            'trip_report_id' => $latestReport->id,
            'species_id' => $species->id,
            'count' => 40,
        ]);

        $this->actingAs($user)
            ->get(route('counts.index'))
            ->assertOk()
            ->assertSee('July 5, 2026')
            ->assertSee('7/5/2026')
            ->assertSee('40 Yellowtail')
            ->assertDontSee('6/15/2026')
            ->assertDontSee('15 Yellowtail');
    }

    public function test_counts_index_defaults_to_latest_available_count_date_for_direct_filter_links(): void
    {
        $user = User::factory()->create();
        $context = $this->countContext();
        $yellowtail = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $rockfish = Species::query()->create(['name' => 'Rockfish', 'slug' => 'rockfish']);

        $yellowtailReport = $this->tripReport($context, '2026-06-15', true, 20, 'yellowtail-report');
        SpeciesCount::query()->create([
            'trip_report_id' => $yellowtailReport->id,
            'species_id' => $yellowtail->id,
            'count' => 15,
        ]);

        $rockfishReport = $this->tripReport($context, '2026-07-05', true, 18, 'rockfish-report');
        SpeciesCount::query()->create([
            'trip_report_id' => $rockfishReport->id,
            'species_id' => $rockfish->id,
            'count' => 40,
        ]);

        $this->actingAs($user)
            ->get(route('counts.index', ['species_id' => $yellowtail->id]))
            ->assertOk()
            ->assertSee('June 15, 2026')
            ->assertSee('15 Yellowtail')
            ->assertDontSee('7/5/2026')
            ->assertDontSee('40 Rockfish');
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
                'from' => '06/01/2026',
                'to' => '06/30/2026',
                'alert_rule_id' => $rule->id,
                'level' => ScoreLevel::Hot->value,
                'minimum_score' => 80,
            ]))
            ->assertOk()
            ->assertSee('6/15/2026')
            ->assertSee('Local Yellowtail')
            ->assertSee('Resend')
            ->assertSee('82')
            ->assertDontSee('Breadth')
            ->assertDontSee('Per Angler')
            ->assertDontSee('Other User Rule')
            ->assertDontSee('999');
    }

    public function test_scores_index_renders_level_badges_with_stateful_backgrounds(): void
    {
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-15', 'status' => ScoreRunStatus::Succeeded]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'species_id' => $species->id,
            'name' => 'Local Yellowtail',
            'minimum_score' => 70,
        ]);
        $badgeClasses = [
            ScoreLevel::WideOpen->value => 'bg-[#fee2e2] text-[#991b1b] ring-[#fecaca]',
            ScoreLevel::Hot->value => 'bg-[#ffedd5] text-[#9a3412] ring-[#fed7aa]',
            ScoreLevel::Active->value => 'bg-[#dcfce7] text-[#166534] ring-[#bbf7d0]',
            ScoreLevel::Watch->value => 'bg-[#fef3c7] text-[#92400e] ring-[#fde68a]',
            ScoreLevel::Cold->value => 'bg-[#f1f5f9] text-[#475569] ring-[#cbd5e1]',
        ];

        foreach (ScoreLevel::cases() as $index => $level) {
            ScoreResult::query()->create([
                'score_run_id' => $scoreRun->id,
                'alert_rule_id' => $rule->id,
                'score_date' => sprintf('2026-06-%02d', 15 + $index),
                'score' => match ($level) {
                    ScoreLevel::WideOpen => 95,
                    ScoreLevel::Hot => 85,
                    ScoreLevel::Active => 75,
                    ScoreLevel::Watch => 65,
                    ScoreLevel::Cold => 55,
                },
                'level' => $level,
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
        }

        $response = $this->actingAs($user)->get(route('scores.index', [
            'from' => '06/01/2026',
            'to' => '06/30/2026',
        ]));

        $response->assertOk();

        foreach (ScoreLevel::cases() as $level) {
            $response
                ->assertSee(str($level->value)->replace('_', ' ')->title())
                ->assertSee("inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {$badgeClasses[$level->value]}", false);
        }
    }

    public function test_user_can_resend_hot_bite_email_for_their_score(): void
    {
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-15', 'status' => ScoreRunStatus::Succeeded]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'species_id' => $species->id,
            'name' => 'Local Yellowtail',
            'minimum_score' => 70,
        ]);
        $score = ScoreResult::query()->create([
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

        Notification::fake();

        $this->actingAs($user)
            ->post(route('scores.hot-bite-email', $score))
            ->assertRedirect()
            ->assertSessionHas('status', "Hot bite email resent to {$user->email}.");

        Notification::assertSentTo($user, HotBiteAlertNotification::class);

        $this->assertDatabaseHas('alert_events', [
            'user_id' => $user->id,
            'alert_rule_id' => $rule->id,
            'score_result_id' => $score->id,
            'event_type' => AlertEventType::ThresholdCrossed->value,
            'level' => ScoreLevel::Hot->value,
            'score' => 82,
            'status' => AlertEventStatus::Sent->value,
        ]);
        $this->assertTrue(
            AlertEvent::query()->where('score_result_id', $score->id)->firstOrFail()->event_date->isSameDay('2026-06-15')
        );
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'channel' => NotificationChannel::Email->value,
            'notification_type' => HotBiteAlertNotification::class,
            'status' => NotificationDeliveryStatus::Sent->value,
        ]);
    }

    public function test_user_cannot_resend_hot_bite_email_for_another_users_score(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-15', 'status' => ScoreRunStatus::Succeeded]);
        $otherRule = AlertRule::query()->create([
            'user_id' => $otherUser->id,
            'species_id' => $species->id,
            'name' => 'Other Yellowtail',
            'minimum_score' => 70,
        ]);
        $otherScore = ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $otherRule->id,
            'score_date' => '2026-06-15',
            'score' => 82,
            'level' => ScoreLevel::Hot,
            'total_count' => 100,
            'boat_count' => 2,
            'landing_count' => 1,
            'explanation' => ['test' => true],
        ]);

        Notification::fake();

        $this->actingAs($user)
            ->post(route('scores.hot-bite-email', $otherScore))
            ->assertForbidden();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('alert_events', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_hot_bite_email_resend_route_is_throttled(): void
    {
        $this->assertContains(
            'throttle:6,1',
            Route::getRoutes()->getByName('scores.hot-bite-email')->gatherMiddleware(),
        );
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
