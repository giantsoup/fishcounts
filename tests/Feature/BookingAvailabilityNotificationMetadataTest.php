<?php

namespace Tests\Feature;

use App\Enums\BookingProvider;
use App\Enums\NotificationChannel;
use App\Enums\ScoreLevel;
use App\Enums\SourceType;
use App\Jobs\SendWeeklyDigestJob;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\NotificationDelivery;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingAvailabilityNotificationMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_weekly_digest_uses_the_next_bookable_trip_for_a_historical_report(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'https://seaforth.fishingreservations.net/sales/' => Http::response($this->seaforthBoatFilterFixture(), 200),
            'seaforth.fishingreservations.net/*' => Http::response($this->seaforthFixture(), 200),
        ]);
        Notification::fake();

        $user = User::factory()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create([
            'region_id' => $region->id,
            'name' => 'Seaforth Landing',
            'slug' => 'seaforth-landing',
            'website_url' => 'https://www.seaforthlanding.com',
            'booking_provider' => BookingProvider::FishingReservations,
            'booking_base_url' => 'https://seaforth.fishingreservations.net/sales/',
        ]);
        $boat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'San Diego',
            'slug' => 'san-diego',
            'booking_provider_identifier' => 'stale-identifier',
        ]);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        $source = ScrapeSource::query()->create([
            'name' => 'Seaforth Landing',
            'slug' => 'seaforth_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.seaforthlanding.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-07-07',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-07',
            'url' => 'https://www.seaforthlanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'name' => 'Local Yellowtail',
            'species_id' => $species->id,
            'include_in_weekly_digest' => true,
        ]);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-07-07']);
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-07-07',
            'score' => 82,
            'level' => ScoreLevel::Hot,
            'total_count' => 40,
            'boat_count' => 1,
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
            'trip_date' => '2026-07-06',
            'source_trip_identifier' => 'san-diego-2026-07-06',
            'anglers' => 36,
            'raw_boat_name' => 'San Diego',
            'raw_landing_name' => 'Seaforth Landing',
            'raw_trip_type' => 'Full Day',
            'dedupe_key' => 'san-diego-2026-07-06',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $tripReport->id,
            'species_id' => $species->id,
            'count' => 40,
            'raw_species_name' => 'Yellowtail',
        ]);

        SendWeeklyDigestJob::dispatchSync($user->id, '2026-07-07', NotificationChannel::Email);

        $delivery = NotificationDelivery::query()->firstOrFail();
        $snapshot = $delivery->metadata['booking_availability'][0]['trip_recommendations'][0]['booking_availability'];

        $this->assertSame('https://seaforth.fishingreservations.net/sales/user.php?trip_id=1048920', $snapshot['booking_url']);
        $this->assertSame('1048920', $snapshot['provider_trip_id']);
        $this->assertSame('Tue, Jul 7, 2026 at 5:30 AM PDT', $snapshot['departure_at_display']);
        $this->assertTrue($snapshot['is_direct_booking']);
        $this->assertSame(19, $snapshot['open_spots']);
        $this->assertSame('Jul 6, 2026 at 10:42 AM PDT', $snapshot['availability_pulled_at_display']);
        $this->assertSame('248', $boat->fresh()->booking_provider_identifier);

        Notification::assertSentTo($user, WeeklyFishingDigestNotification::class, function (WeeklyFishingDigestNotification $notification) use ($user): bool {
            $html = (string) $notification->toMail($user)->render();

            return str_contains($html, 'https://seaforth.fishingreservations.net/sales/user.php?trip_id=1048920')
                && str_contains($html, '40 Yellowtail caught 7/6/26')
                && str_contains($html, 'Next matching departure:')
                && str_contains($html, 'Tue, Jul 7, 2026 at 5:30 AM PDT')
                && str_contains($html, '19 spots open.')
                && str_contains($html, 'Availability checked Jul 6, 2026 at 10:42 AM PDT.');
        });
        Http::assertSentCount(2);
    }

    public function test_weekly_digest_delivery_metadata_stores_hm_landing_booking_snapshot(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'www.hmlanding.com/xolacache*' => Http::response($this->hmLandingFixture(), 200),
        ]);
        Notification::fake();

        $user = User::factory()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create([
            'region_id' => $region->id,
            'name' => 'H&M Landing',
            'slug' => 'hm-landing',
            'website_url' => 'https://www.hmlanding.com',
            'booking_provider' => BookingProvider::HmLanding,
            'booking_base_url' => 'https://www.hmlanding.com',
        ]);
        $boat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Grande',
            'slug' => 'grande',
        ]);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        $source = ScrapeSource::query()->create([
            'name' => 'H&M Landing',
            'slug' => 'hm_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.hmlanding.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-07-07',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-07',
            'url' => 'https://www.fishcounts.com/hmlanding/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'name' => 'H&M Yellowtail',
            'species_id' => $species->id,
            'include_in_weekly_digest' => true,
        ]);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-07-07']);
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-07-07',
            'score' => 82,
            'level' => ScoreLevel::Hot,
            'total_count' => 32,
            'boat_count' => 1,
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
            'trip_date' => '2026-07-06',
            'source_trip_identifier' => 'grande-2026-07-06',
            'anglers' => 40,
            'raw_boat_name' => 'Grande',
            'raw_landing_name' => 'H&M Landing',
            'raw_trip_type' => 'Full Day',
            'dedupe_key' => 'grande-2026-07-06',
        ]);
        SpeciesCount::query()->create([
            'trip_report_id' => $tripReport->id,
            'species_id' => $species->id,
            'count' => 32,
            'raw_species_name' => 'Yellowtail',
        ]);

        SendWeeklyDigestJob::dispatchSync($user->id, '2026-07-07', NotificationChannel::Email);

        $delivery = NotificationDelivery::query()->firstOrFail();
        $snapshot = $delivery->metadata['booking_availability'][0]['trip_recommendations'][0]['booking_availability'];

        $this->assertSame('https://www.hmlanding.com/boat/grande#tab-open-trips', $snapshot['booking_url']);
        $this->assertNull($snapshot['provider_trip_id']);
        $this->assertFalse($snapshot['is_direct_booking']);
        $this->assertSame('provider_page_only', $snapshot['fallback_reason']);
        $this->assertSame('Tue, Jul 7, 2026 at 5:30 AM PDT', $snapshot['departure_at_display']);
        $this->assertSame(40, $snapshot['open_spots']);
        $this->assertSame('Jul 6, 2026 at 10:42 AM PDT', $snapshot['availability_pulled_at_display']);
        $this->assertSame('5a9062efe0179894348b45ca', $snapshot['provider_metadata']['xola_experience_id']);
        $this->assertSame('53e93b35ad2171ef768b4588', $snapshot['provider_metadata']['seller_id']);
        $this->assertNull($boat->fresh()->booking_provider_identifier);

        Notification::assertSentTo($user, WeeklyFishingDigestNotification::class, function (WeeklyFishingDigestNotification $notification) use ($user): bool {
            $html = (string) $notification->toMail($user)->render();

            return str_contains($html, 'https://www.hmlanding.com/boat/grande#tab-open-trips')
                && str_contains($html, '32 Yellowtail caught 7/6/26')
                && str_contains($html, 'Next matching departure:')
                && str_contains($html, 'Tue, Jul 7, 2026 at 5:30 AM PDT')
                && str_contains($html, '40 spots open.')
                && str_contains($html, 'Availability checked Jul 6, 2026 at 10:42 AM PDT.');
        });
        Http::assertSentCount(1);
    }

    private function seaforthFixture(): string
    {
        return <<<'HTML'
            <table>
                <tr>
                    <td class="trip-cell" data-trip-id="1048920"><strong>San Diego</strong><br>Full Day (Passport Required)</td>
                    <td>Tue. 7-7-2026 5:30 AM</td>
                    <td>Tue. 7-7-2026 5:00 PM</td>
                    <td>36</td>
                    <td>$250</td>
                    <td><span class="font_green13">&#49;&#57;</span></td>
                    <td><a href="/sales/user.php?trip_id=1048920" class="green_butn">Book</a></td>
                </tr>
            </table>
        HTML;
    }

    private function seaforthBoatFilterFixture(): string
    {
        return <<<'HTML'
            <form>
                <select name="boat_filter[]" multiple="multiple">
                    <option value="248">San Diego</option>
                    <option value="249">Sea Watch</option>
                </select>
            </form>
        HTML;
    }

    private function hmLandingFixture(): string
    {
        return '/**/ typeof JSON_CALLBACK === \'function\' && JSON_CALLBACK('.json_encode([
            'trips' => [
                [
                    'date' => '2026-07-07T00:00:00-07:00',
                    'time' => '530',
                    'datetime' => '2026-07-07T12:30:00.000Z',
                    'price' => 250,
                    'open_spots' => 40,
                    'reserved_spots' => 0,
                    'note' => 'VALID PASSPORT REQUIRED!',
                    'expId' => '5a9062efe0179894348b45ca',
                ],
            ],
            'experiences' => [
                '5a9062efe0179894348b45ca' => [
                    'id' => '5a9062efe0179894348b45ca',
                    'name' => 'Grande - Full Day - Coronado Islands',
                    'duration' => 720,
                ],
            ],
        ], JSON_THROW_ON_ERROR).');';
    }
}
