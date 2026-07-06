<?php

namespace Tests\Unit;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use App\Services\Booking\BookingUrlResolver;
use Tests\TestCase;

class BookingUrlResolverTest extends TestCase
{
    public function test_manual_booking_url_wins(): void
    {
        $landing = new Landing([
            'booking_provider' => BookingProvider::FishingReservations,
            'booking_base_url' => 'https://fishermanslanding.fishingreservations.net/resos/',
        ]);
        $boat = new Boat([
            'booking_url' => 'https://booking.example.test/pacific-queen',
            'booking_provider_identifier' => '201',
        ]);

        $this->assertSame(
            'https://booking.example.test/pacific-queen',
            app(BookingUrlResolver::class)->resolve($boat, $landing, 'https://source.example.test/fishcounts'),
        );
    }

    public function test_fishing_reservations_provider_identifier_builds_filtered_url(): void
    {
        $landing = new Landing([
            'booking_provider' => BookingProvider::FishingReservations,
            'booking_base_url' => 'https://fishermanslanding.fishingreservations.net/resos/',
            'website_url' => 'https://www.fishermanslanding.com',
        ]);
        $boat = new Boat(['booking_provider_identifier' => '201']);

        $this->assertSame(
            'https://fishermanslanding.fishingreservations.net/resos/?boat_filter%5B0%5D=201',
            app(BookingUrlResolver::class)->resolve($boat, $landing),
        );
    }

    public function test_fishing_reservations_without_provider_identifier_uses_booking_base_url(): void
    {
        $landing = new Landing([
            'booking_provider' => BookingProvider::FishingReservations,
            'booking_base_url' => 'https://seaforth.fishingreservations.net/sales/',
            'website_url' => 'https://www.seaforthlanding.com',
        ]);

        $this->assertSame(
            'https://seaforth.fishingreservations.net/sales/',
            app(BookingUrlResolver::class)->resolve(new Boat, $landing),
        );
    }

    public function test_hm_landing_builds_boat_open_trips_url(): void
    {
        $landing = new Landing([
            'booking_provider' => BookingProvider::HmLanding,
            'booking_base_url' => 'https://www.hmlanding.com',
        ]);
        $boat = new Boat(['slug' => 'grande']);

        $this->assertSame(
            'https://www.hmlanding.com/boat/grande#tab-open-trips',
            app(BookingUrlResolver::class)->resolve($boat, $landing),
        );
    }

    public function test_fallback_uses_landing_website_then_source_url(): void
    {
        $resolver = app(BookingUrlResolver::class);

        $this->assertSame(
            'https://landing.example.test',
            $resolver->resolve(new Boat, new Landing(['website_url' => 'https://landing.example.test']), 'https://source.example.test'),
        );

        $this->assertSame(
            'https://source.example.test',
            $resolver->resolve(new Boat, new Landing, 'https://source.example.test'),
        );
    }
}
