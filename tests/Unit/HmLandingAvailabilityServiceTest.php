<?php

namespace Tests\Unit;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use App\Services\Booking\HmLandingAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HmLandingAvailabilityServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_resolves_hm_boat_url_with_exact_availability_snapshot_and_prefers_matching_trip_type(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'www.hmlanding.com/xolacache*' => Http::response($this->jsonpFixture(), 200),
        ]);

        $availability = app(HmLandingAvailabilityService::class)->resolve(
            new Boat(['name' => 'Grande', 'slug' => 'grande']),
            $this->hmLanding(),
            CarbonImmutable::parse('2026-07-07'),
            'Full Day',
        );

        $this->assertSame('https://www.hmlanding.com/boat/grande#tab-open-trips', $availability->bookingUrl);
        $this->assertNull($availability->providerTripId);
        $this->assertFalse($availability->isDirectBooking);
        $this->assertSame('provider_page_only', $availability->fallbackReason);
        $this->assertSame(40, $availability->openSpots);
        $this->assertSame('Bookable', $availability->statusText);
        $this->assertSame('Jul 6, 2026 at 10:42 AM PDT', $availability->availabilityPulledAtDisplay());
        $this->assertSame('5a9062efe0179894348b45ca', $availability->providerMetadata['xola_experience_id']);
        $this->assertSame('2026-07-07', $availability->providerMetadata['arrival']);
        $this->assertSame('530', $availability->providerMetadata['arrival_time']);

        Http::assertSentCount(1);
    }

    public function test_parser_marks_sold_departed_and_call_only_rows_as_not_directly_bookable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 05:10:00', 'America/Los_Angeles'));

        $options = app(HmLandingAvailabilityService::class)->parseTripOptions(
            $this->statusJsonpFixture(),
            CarbonImmutable::parse('2026-07-06 05:10:00', 'America/Los_Angeles'),
        );

        $this->assertSame(['Departed', 'Call to Book', 'Sold Out'], $options->pluck('statusText')->all());
        $this->assertSame([false, false, false], $options->pluck('isBookable')->all());
    }

    public function test_it_falls_back_when_matching_date_is_not_directly_bookable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'www.hmlanding.com/xolacache*' => Http::response($this->soldOutJsonpFixture(), 200),
        ]);

        $availability = app(HmLandingAvailabilityService::class)->resolve(
            new Boat(['name' => 'Grande', 'slug' => 'grande']),
            $this->hmLanding(),
            CarbonImmutable::parse('2026-07-07'),
            'Full Day',
        );

        $this->assertSame('https://www.hmlanding.com/boat/grande#tab-open-trips', $availability->bookingUrl);
        $this->assertFalse($availability->isDirectBooking);
        $this->assertSame('exact_trip_not_available', $availability->fallbackReason);
        $this->assertSame('Sold Out', $availability->statusText);
        $this->assertSame(0, $availability->providerMetadata['open_spots'] ?? $availability->openSpots);
    }

    public function test_provider_failures_return_fallback_url_without_throwing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.hmlanding.com/xolacache*' => Http::response('server error', 500),
        ]);

        $availability = app(HmLandingAvailabilityService::class)->resolve(
            new Boat(['name' => 'Grande', 'slug' => 'grande']),
            $this->hmLanding(),
            CarbonImmutable::parse('2026-07-07'),
            'Full Day',
        );

        $this->assertSame('https://www.hmlanding.com/boat/grande#tab-open-trips', $availability->bookingUrl);
        $this->assertFalse($availability->isDirectBooking);
        $this->assertSame('provider_request_failed', $availability->fallbackReason);
    }

    public function test_it_memoizes_provider_fetches_during_one_service_lifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'www.hmlanding.com/xolacache*' => Http::response($this->jsonpFixture(), 200),
        ]);

        $service = app(HmLandingAvailabilityService::class);
        $boat = new Boat(['name' => 'Grande', 'slug' => 'grande']);
        $landing = $this->hmLanding();

        $service->resolve($boat, $landing, CarbonImmutable::parse('2026-07-07'), 'Full Day');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:05', 'America/Los_Angeles'));
        $service->resolve($boat, $landing, CarbonImmutable::parse('2026-07-07'), 'Full Day');

        Http::assertSentCount(1);
    }

    private function hmLanding(): Landing
    {
        return new Landing([
            'name' => 'H&M Landing',
            'booking_provider' => BookingProvider::HmLanding,
            'booking_base_url' => 'https://www.hmlanding.com',
        ]);
    }

    private function jsonpFixture(): string
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
                [
                    'date' => '2026-07-07T00:00:00-07:00',
                    'time' => '1900',
                    'datetime' => '2026-07-08T02:00:00.000Z',
                    'price' => 500,
                    'open_spots' => 6,
                    'reserved_spots' => 20,
                    'expId' => '5b1d94eae01798b00a8b460b',
                ],
            ],
            'experiences' => [
                '5a9062efe0179894348b45ca' => [
                    'id' => '5a9062efe0179894348b45ca',
                    'name' => 'Grande - Full Day - Coronado Islands',
                    'duration' => 720,
                ],
                '5b1d94eae01798b00a8b460b' => [
                    'id' => '5b1d94eae01798b00a8b460b',
                    'name' => 'Grande - 1.5 Day - Freelance',
                    'duration' => 2100,
                ],
            ],
        ], JSON_THROW_ON_ERROR).');';
    }

    private function statusJsonpFixture(): string
    {
        return '/**/ typeof JSON_CALLBACK === \'function\' && JSON_CALLBACK('.json_encode([
            'trips' => [
                ['date' => '2026-07-05T00:00:00-07:00', 'time' => '530', 'datetime' => '2026-07-05T12:30:00.000Z', 'open_spots' => 10, 'reserved_spots' => 5, 'expId' => 'exp-full'],
                ['date' => '2026-07-06T00:00:00-07:00', 'time' => '530', 'datetime' => '2026-07-06T12:30:00.000Z', 'open_spots' => 10, 'reserved_spots' => 5, 'expId' => 'exp-full'],
                ['date' => '2026-07-07T00:00:00-07:00', 'time' => '530', 'datetime' => '2026-07-07T12:30:00.000Z', 'open_spots' => 0, 'reserved_spots' => 40, 'expId' => 'exp-full'],
            ],
            'experiences' => [
                'exp-full' => ['id' => 'exp-full', 'name' => 'Grande - Full Day - Coronado Islands', 'duration' => 720],
            ],
        ], JSON_THROW_ON_ERROR).');';
    }

    private function soldOutJsonpFixture(): string
    {
        return '/**/ typeof JSON_CALLBACK === \'function\' && JSON_CALLBACK('.json_encode([
            'trips' => [
                [
                    'date' => '2026-07-07T00:00:00-07:00',
                    'time' => '530',
                    'datetime' => '2026-07-07T12:30:00.000Z',
                    'price' => 250,
                    'open_spots' => 0,
                    'reserved_spots' => 40,
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
