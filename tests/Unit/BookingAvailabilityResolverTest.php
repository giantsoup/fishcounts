<?php

namespace Tests\Unit;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use App\Services\Booking\BookingAvailabilityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingAvailabilityResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_fishing_reservations_provider_dispatches_to_exact_availability_service(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'seaforth.fishingreservations.net/*' => Http::response($this->fishingReservationsFixture(), 200),
        ]);

        $availability = app(BookingAvailabilityResolver::class)->resolve(
            new Boat(['name' => 'San Diego', 'slug' => 'san-diego', 'booking_provider_identifier' => '248']),
            new Landing([
                'booking_provider' => BookingProvider::FishingReservations,
                'booking_base_url' => 'https://seaforth.fishingreservations.net/sales/',
            ]),
            CarbonImmutable::parse('2026-07-07'),
            'Full Day',
        );

        $this->assertSame('https://seaforth.fishingreservations.net/sales/user.php?trip_id=1048920', $availability->bookingUrl);
        $this->assertSame('1048920', $availability->providerTripId);
        $this->assertTrue($availability->isDirectBooking);
    }

    public function test_hm_provider_dispatches_to_hm_landing_availability_service(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'www.hmlanding.com/xolacache*' => Http::response($this->hmJsonpFixture(), 200),
        ]);

        $availability = app(BookingAvailabilityResolver::class)->resolve(
            new Boat(['name' => 'Grande', 'slug' => 'grande']),
            new Landing([
                'booking_provider' => BookingProvider::HmLanding,
                'booking_base_url' => 'https://www.hmlanding.com',
            ]),
            CarbonImmutable::parse('2026-07-07'),
            'Full Day',
        );

        $this->assertSame('https://www.hmlanding.com/boat/grande#tab-open-trips', $availability->bookingUrl);
        $this->assertNull($availability->providerTripId);
        $this->assertFalse($availability->isDirectBooking);
        $this->assertSame('provider_page_only', $availability->fallbackReason);
        $this->assertSame('5a9062efe0179894348b45ca', $availability->providerMetadata['xola_experience_id']);
    }

    public function test_unconfigured_provider_falls_back_to_generic_booking_url(): void
    {
        $availability = app(BookingAvailabilityResolver::class)->resolve(
            new Boat(['name' => 'Unknown', 'slug' => 'unknown']),
            new Landing(['website_url' => 'https://landing.example.test']),
            CarbonImmutable::parse('2026-07-07'),
            'Full Day',
            'https://source.example.test',
        );

        $this->assertSame('https://landing.example.test', $availability->bookingUrl);
        $this->assertFalse($availability->isDirectBooking);
        $this->assertSame('provider_not_configured', $availability->fallbackReason);
    }

    private function fishingReservationsFixture(): string
    {
        return <<<'HTML'
            <table>
                <tr>
                    <td class="trip-cell" data-trip-id="1048920"><strong>San Diego</strong><br>Full Day</td>
                    <td>Tue. 7-7-2026 5:30 AM</td>
                    <td>Tue. 7-7-2026 5:00 PM</td>
                    <td>36</td>
                    <td>$250</td>
                    <td>19</td>
                    <td><a href="/sales/user.php?trip_id=1048920">Book</a></td>
                </tr>
            </table>
        HTML;
    }

    private function hmJsonpFixture(): string
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
