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
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
        $boat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Pacific Queen', 'slug' => 'pacific-queen']);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $tripType = TripType::query()->create(['name' => 'Overnight', 'slug' => 'overnight']);
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
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
        ]);

        $html = (string) (new WeeklyFishingDigestNotification($user, CarbonImmutable::parse('2026-06-20')))
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('Weekly fishing digest', $html);
        $this->assertStringContainsString('LelloTail', $html);
        $this->assertStringContainsString('Wide Open', $html);
        $this->assertStringContainsString('Weekly fish', $html);
        $this->assertStringContainsString('Best day', $html);
        $this->assertStringContainsString('Top boats', $html);
        $this->assertStringContainsString('Pacific Queen', $html);
        $this->assertStringContainsString('Top landings', $html);
        $this->assertStringContainsString('Data quality', $html);
        $this->assertStringNotContainsString('wide_open', $html);
        $this->assertStringNotContainsString('Thanks,', $html);
    }
}
