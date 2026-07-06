<?php

namespace Tests\Unit;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use App\Services\Booking\FishingReservationsAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingAvailabilityServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_resolves_exact_booking_url_and_prefers_matching_trip_type(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'seaforth.fishingreservations.net/*' => Http::response($this->seaforthFixture(), 200),
        ]);

        $availability = app(FishingReservationsAvailabilityService::class)->resolve(
            new Boat(['name' => 'San Diego', 'slug' => 'san-diego', 'booking_provider_identifier' => '248']),
            new Landing([
                'booking_provider' => BookingProvider::FishingReservations,
                'booking_base_url' => 'https://seaforth.fishingreservations.net/sales/',
            ]),
            CarbonImmutable::parse('2026-07-07'),
            'Full Day',
            'https://www.seaforthlanding.com/fishcounts.php',
        );

        $this->assertSame('https://seaforth.fishingreservations.net/sales/user.php?trip_id=1048920', $availability->bookingUrl);
        $this->assertSame('1048920', $availability->providerTripId);
        $this->assertTrue($availability->isDirectBooking);
        $this->assertSame(19, $availability->openSpots);
        $this->assertSame('Jul 6, 2026 at 10:42 AM PDT', $availability->availabilityPulledAtDisplay());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://seaforth.fishingreservations.net/sales/?boat_filter%5B0%5D=248&mode=table');
    }

    public function test_it_falls_back_when_matching_date_is_not_directly_bookable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'));
        Http::preventStrayRequests();
        Http::fake([
            'fishermanslanding.fishingreservations.net/*' => Http::response($this->fullTripFixture(), 200),
        ]);

        $availability = app(FishingReservationsAvailabilityService::class)->resolve(
            new Boat(['name' => 'Pacific Queen', 'slug' => 'pacific-queen', 'booking_provider_identifier' => '201']),
            new Landing([
                'booking_provider' => BookingProvider::FishingReservations,
                'booking_base_url' => 'https://fishermanslanding.fishingreservations.net/resos/',
            ]),
            CarbonImmutable::parse('2026-07-07'),
            'Overnight',
        );

        $this->assertSame('https://fishermanslanding.fishingreservations.net/resos/?boat_filter%5B0%5D=201', $availability->bookingUrl);
        $this->assertFalse($availability->isDirectBooking);
        $this->assertSame('exact_trip_not_available', $availability->fallbackReason);
        $this->assertStringContainsString('Pacific Queen', (string) $availability->statusText);
    }

    public function test_provider_failures_return_fallback_url_without_throwing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pointloma.fishingreservations.net/*' => Http::response('server error', 500),
        ]);

        $availability = app(FishingReservationsAvailabilityService::class)->resolve(
            new Boat(['name' => 'Mission Belle', 'slug' => 'mission-belle', 'booking_provider_identifier' => '214']),
            new Landing([
                'booking_provider' => BookingProvider::FishingReservations,
                'booking_base_url' => 'https://pointloma.fishingreservations.net/sales/',
            ]),
            CarbonImmutable::parse('2026-07-07'),
            '3/4 Day',
        );

        $this->assertSame('https://pointloma.fishingreservations.net/sales/?boat_filter%5B0%5D=214', $availability->bookingUrl);
        $this->assertFalse($availability->isDirectBooking);
        $this->assertSame('provider_request_failed', $availability->fallbackReason);
    }

    public function test_parser_excludes_non_booking_rows_from_direct_booking(): void
    {
        $options = app(FishingReservationsAvailabilityService::class)->parseTripOptions(
            $this->fullTripFixture(),
            'https://fishermanslanding.fishingreservations.net/resos/?boat_filter%5B0%5D=201',
            CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'),
        );

        $this->assertCount(1, $options);
        $this->assertSame('555', $options[0]->providerTripId);
        $this->assertFalse($options[0]->isBookable);
        $this->assertNull($options[0]->bookingUrl);
    }

    public function test_parser_resolves_relative_booking_links_against_provider_directory(): void
    {
        $options = app(FishingReservationsAvailabilityService::class)->parseTripOptions(
            <<<'HTML'
                <table>
                    <tr>
                        <td class="trip-cell" data-trip-id="123"><strong>San Diego</strong><br>Full Day</td>
                        <td>Tue. 7-7-2026 5:30 AM</td>
                        <td>Tue. 7-7-2026 5:00 PM</td>
                        <td>36</td>
                        <td>$250</td>
                        <td>19</td>
                        <td><a href="user.php?trip_id=123">Book</a></td>
                    </tr>
                </table>
            HTML,
            'https://seaforth.fishingreservations.net/sales/?boat_filter%5B0%5D=248',
            CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'),
        );

        $this->assertSame('https://seaforth.fishingreservations.net/sales/user.php?trip_id=123', $options[0]->bookingUrl);
    }

    public function test_parser_reads_live_responsive_fishing_reservations_rows(): void
    {
        $options = app(FishingReservationsAvailabilityService::class)->parseTripOptions(
            <<<'HTML'
                <table>
                    <tr>
                        <td rowspan="2" class="scale-data scale-light trip-cell" data-trip-id="1048920"><a href="/sales/user.php?trip_id=1048920" class="green_butn" id="trip-1048920-btn"></a></td>
                        <td class="scale-data scale-light trip-cell" data-trip-id="1048920">
                            <div class="row">
                                <div class="col-md-4 col-sm-12 trip-info"><span class="lbl"></span><strong>San Diego</strong><br>Full Day (Passport Required)<br><span class="charter-alert"><em>Passport Required</em></span></div>
                                <div class="col-md-2 col-sm-6 col-xs-12 trip-depart"><span class="lbl"></span>Tue. 7-7-2026 <br>5:30 AM</div>
                                <div class="col-md-2 col-sm-6 col-xs-12 trip-return"><span class="lbl"></span>Tue. 7-7-2026 <br>5:00 PM</div>
                                <div class="col-md-1 col-sm-4 col-xs-4 trip-load"><span class="lbl"></span>36</div>
                                <div class="col-md-1 col-sm-4 col-xs-4 trip-price"><span class="lbl"></span>$250</div>
                                <div class="col-md-2 col-sm-4 col-xs-4 trip-spots"><span class="lbl"></span><span class="font_green13">&#49;&#57;</span></div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="scale-group scale-light trip-cell" data-trip-id="1048920"><div class="trip-comments">Full Day Trip fishing Offshore.</div></td>
                    </tr>
                </table>
            HTML,
            'https://seaforth.fishingreservations.net/sales/?boat_filter%5B0%5D=248&mode=table',
            CarbonImmutable::parse('2026-07-06 10:42:00', 'America/Los_Angeles'),
        );

        $this->assertCount(1, $options);
        $this->assertSame('1048920', $options[0]->providerTripId);
        $this->assertSame('2026-07-07 05:30:00', $options[0]->departAt?->toDateTimeString());
        $this->assertStringContainsString('Full Day', (string) $options[0]->tripTypeText);
        $this->assertSame(19, $options[0]->openSpots);
        $this->assertSame('https://seaforth.fishingreservations.net/sales/user.php?trip_id=1048920', $options[0]->bookingUrl);
        $this->assertSame('Full Day Trip fishing Offshore.', $options[0]->comments);
    }

    private function seaforthFixture(): string
    {
        return <<<'HTML'
            <table>
                <tr>
                    <td class="trip-cell" data-trip-id="1048919"><strong>San Diego</strong><br>Half Day</td>
                    <td>Tue. 7-7-2026 6:00 AM</td>
                    <td>Tue. 7-7-2026 12:30 PM</td>
                    <td>30</td>
                    <td>$120</td>
                    <td><span class="font_green13">8</span></td>
                    <td><a href="/sales/user.php?trip_id=1048919" class="green_butn">Book</a></td>
                </tr>
                <tr>
                    <td class="trip-cell" data-trip-id="1048920"><strong>San Diego</strong><br>Full Day (Passport Required)</td>
                    <td>Tue. 7-7-2026 5:30 AM</td>
                    <td>Tue. 7-7-2026 5:00 PM</td>
                    <td>36</td>
                    <td>$250</td>
                    <td><span class="font_green13">&#49;&#57;</span></td>
                    <td><a href="/sales/user.php?trip_id=1048920" class="green_butn">Book</a></td>
                </tr>
                <tr class="scale-group"><td colspan="7">Bring passport.</td></tr>
            </table>
        HTML;
    }

    private function fullTripFixture(): string
    {
        return <<<'HTML'
            <table>
                <tr>
                    <td class="trip-cell" data-trip-id="555"><strong>Pacific Queen</strong><br>Overnight</td>
                    <td>Tue. 7-7-2026 9:00 PM</td>
                    <td>Wed. 7-8-2026 7:00 PM</td>
                    <td>30</td>
                    <td>$450</td>
                    <td>0</td>
                    <td><span class="call-btn">Full - Call Landing</span></td>
                </tr>
            </table>
        HTML;
    }
}
