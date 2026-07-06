<?php

namespace App\Services\Booking;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use Carbon\CarbonImmutable;

class BookingAvailabilityResolver
{
    public function __construct(
        private readonly BookingUrlResolver $bookingUrlResolver,
        private readonly FishingReservationsAvailabilityService $fishingReservationsAvailabilityService,
        private readonly HmLandingAvailabilityService $hmLandingAvailabilityService,
    ) {}

    public function resolve(
        ?Boat $boat,
        ?Landing $landing,
        CarbonImmutable $targetDate,
        ?string $preferredTripType = null,
        ?string $sourceUrl = null,
    ): BookingAvailability {
        $landing ??= $boat?->landing;

        return match ($landing?->booking_provider) {
            BookingProvider::FishingReservations => $this->fishingReservationsAvailabilityService->resolve(
                $boat,
                $landing,
                $targetDate,
                $preferredTripType,
                $sourceUrl,
            ),
            BookingProvider::HmLanding => $this->hmLandingAvailabilityService->resolve(
                $boat,
                $landing,
                $targetDate,
                $preferredTripType,
                $sourceUrl,
            ),
            default => BookingAvailability::fallback(
                $this->bookingUrlResolver->resolve($boat, $landing, $sourceUrl),
                fallbackReason: 'provider_not_configured',
            ),
        };
    }
}
