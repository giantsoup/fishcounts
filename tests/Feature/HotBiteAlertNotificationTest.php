<?php

namespace Tests\Feature;

use App\Enums\AlertEventStatus;
use App\Enums\AlertEventType;
use App\Enums\ScoreLevel;
use App\Enums\SourceType;
use App\Models\AlertEvent;
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
use App\Notifications\HotBiteAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotBiteAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hot_bite_alert_email_renders_structured_score_summary(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create([
            'region_id' => $region->id,
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans-landing',
            'website_url' => 'https://landing.example.test',
        ]);
        $boat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Pacific Queen',
            'slug' => 'pacific-queen',
            'booking_url' => 'https://booking.example.test/pacific-queen',
        ]);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $otherSpecies = Species::query()->create(['name' => 'Bluefin Tuna', 'slug' => 'bluefin-tuna']);
        $tripType = TripType::query()->create(['name' => 'Overnight', 'slug' => 'overnight']);
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-06-20',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'name' => 'LelloTail',
            'species_id' => $species->id,
            'minimum_score' => 70,
        ]);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-20']);
        $scoreResult = ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-20',
            'score' => 93,
            'level' => ScoreLevel::WideOpen,
            'total_count' => 153,
            'total_anglers' => 162,
            'count_per_angler' => 0.94,
            'boat_count' => 4,
            'landing_count' => 3,
            'explanation' => [],
        ]);
        $alertEvent = AlertEvent::query()->create([
            'user_id' => $user->id,
            'alert_rule_id' => $rule->id,
            'score_result_id' => $scoreResult->id,
            'event_type' => AlertEventType::ThresholdCrossed,
            'event_date' => '2026-06-20',
            'level' => ScoreLevel::WideOpen,
            'score' => 93,
            'status' => AlertEventStatus::Pending,
        ]);
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-06-20',
            'moon_phase' => 'Waxing Crescent',
            'moon_illumination_percent' => 21,
            'water_temp_f_avg' => 68.4,
            'swell_height_ft_avg' => 2.5,
            'swell_period_seconds_avg' => 12,
            'swell_direction_degrees_dominant' => 210,
            'condition_summary' => 'moon Waxing Crescent 21%; water 68.4 F; swell 2.5 ft @ 12s SSW.',
            'coverage' => [],
            'is_partial' => false,
        ]);
        $tripReport = TripReport::query()->create([
            'source_id' => $source->id,
            'raw_scrape_payload_id' => $payload->id,
            'region_id' => $region->id,
            'landing_id' => $landing->id,
            'boat_id' => $boat->id,
            'trip_type_id' => $tripType->id,
            'trip_date' => '2026-06-20',
            'source_trip_identifier' => 'pacific-queen-2026-06-20',
            'anglers' => 30,
            'raw_boat_name' => 'Pacific Queen',
            'raw_landing_name' => 'Fisherman\'s Landing',
            'raw_trip_type' => 'Overnight',
            'dedupe_key' => 'hot-bite-alert-email-sample',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $tripReport->id,
            'species_id' => $species->id,
            'count' => 68,
            'raw_species_name' => 'Yellowtail',
            'raw_count_text' => '68 Yellowtail',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $tripReport->id,
            'species_id' => $otherSpecies->id,
            'count' => 4,
            'raw_species_name' => 'Bluefin Tuna',
        ]);

        $html = (string) (new HotBiteAlertNotification($alertEvent))
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('Hot bite threshold crossed', $html);
        $this->assertStringContainsString('<table class="inner-body" align="center" width="100%"', $html);
        $this->assertStringContainsString('max-width: 570px', $html);
        $this->assertStringContainsString('padding: 24px 16px !important;', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('overflow-wrap: break-word', $html);
        $this->assertStringContainsString('word-break: break-word', $html);
        $this->assertStringContainsString('LelloTail', $html);
        $this->assertStringContainsString('Yellowtail', $html);
        $this->assertStringContainsString('Wide Open', $html);
        $this->assertStringContainsString('Threshold', $html);
        $this->assertStringContainsString('Target fish', $html);
        $this->assertStringContainsString('Boats reporting', $html);
        $this->assertStringContainsString('Official conditions', $html);
        $this->assertStringContainsString('moon Waxing Crescent 21%', $html);
        $this->assertStringContainsString('Best trip options for Yellowtail', $html);
        $this->assertStringContainsString('Recommended boats', $html);
        $this->assertStringContainsString('Book', $html);
        $this->assertStringContainsString('Pacific Queen', $html);
        $this->assertStringContainsString('Overnight', $html);
        $this->assertStringContainsString('6/20/26', $html);
        $this->assertStringContainsString('68', $html);
        $this->assertStringContainsString('↗', $html);
        $this->assertStringContainsString('https://www.fishermanslanding.com/fishcounts.php', $html);
        $this->assertStringContainsString('#:~:text=Pacific%20Queen', $html);
        $this->assertStringContainsString('https://booking.example.test/pacific-queen', $html);
        $this->assertStringNotContainsString('6/20/2026', $html);
        $this->assertStringNotContainsString('Count Source', $html);
        $this->assertStringNotContainsString('Fish / angler', $html);
        $this->assertStringNotContainsString('/ Angler', $html);
        $this->assertStringNotContainsString('2.27', $html);
        $this->assertStringNotContainsString('Bluefin Tuna: 4', $html);
        $this->assertStringNotContainsString('/counts?', $html);
        $this->assertStringNotContainsString('wide_open', $html);
        $this->assertStringNotContainsString('Thanks,', $html);
        $this->assertStringNotContainsString('<table class="inner-body" align="center" width="570"', $html);
    }
}
