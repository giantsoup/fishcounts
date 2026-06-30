<?php

namespace Tests\Feature;

use App\Enums\ScoreLevel;
use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\Boat;
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
use App\Notifications\WeeklyFishingDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyFishingDigestNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_digest_email_renders_structured_summary_sections(): void
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
            'include_in_weekly_digest' => true,
        ]);

        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-20']);
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-15',
            'score' => 72,
            'level' => ScoreLevel::Active,
            'total_count' => 31,
            'boat_count' => 2,
            'landing_count' => 1,
            'explanation' => [],
        ]);
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-20',
            'score' => 93,
            'level' => ScoreLevel::WideOpen,
            'total_count' => 68,
            'total_anglers' => 72,
            'count_per_angler' => 0.94,
            'boat_count' => 4,
            'landing_count' => 1,
            'explanation' => [],
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
            'dedupe_key' => 'weekly-digest-email-sample',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $tripReport->id,
            'species_id' => $species->id,
            'count' => 68,
            'raw_species_name' => 'Yellowtail',
            'raw_count_text' => '68 Yellowtail',
        ]);

        $html = (string) (new WeeklyFishingDigestNotification($user, CarbonImmutable::parse('2026-06-20')))
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('Weekly fishing digest', $html);
        $this->assertStringContainsString('LelloTail', $html);
        $this->assertStringContainsString('Wide Open', $html);
        $this->assertStringContainsString('Weekly target fish', $html);
        $this->assertStringContainsString('Best day', $html);
        $this->assertStringContainsString('Best trip options', $html);
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
        $this->assertStringNotContainsString('Top landings', $html);
        $this->assertStringNotContainsString('Count Source', $html);
        $this->assertStringNotContainsString('Fish / angler', $html);
        $this->assertStringNotContainsString('/ Angler', $html);
        $this->assertStringNotContainsString('Data quality', $html);
        $this->assertStringNotContainsString('/counts?', $html);
        $this->assertStringNotContainsString('wide_open', $html);
        $this->assertStringNotContainsString('Thanks,', $html);
    }
}
