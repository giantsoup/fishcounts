<?php

namespace App\Services\Booking;

use Carbon\CarbonImmutable;

class BookingAvailability
{
    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public readonly ?string $bookingUrl,
        public readonly ?string $providerTripId = null,
        public readonly ?CarbonImmutable $departureAt = null,
        public readonly bool $isDirectBooking = false,
        public readonly ?int $openSpots = null,
        public readonly ?CarbonImmutable $availabilityPulledAt = null,
        public readonly ?string $statusText = null,
        public readonly ?string $fallbackReason = null,
        public readonly array $providerMetadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public static function direct(
        ?string $bookingUrl,
        ?string $providerTripId = null,
        ?CarbonImmutable $departureAt = null,
        ?int $openSpots = null,
        ?CarbonImmutable $availabilityPulledAt = null,
        ?string $statusText = null,
        array $providerMetadata = [],
    ): self {
        return new self(
            bookingUrl: $bookingUrl,
            providerTripId: $providerTripId,
            departureAt: $departureAt,
            isDirectBooking: true,
            openSpots: $openSpots,
            availabilityPulledAt: $availabilityPulledAt,
            statusText: $statusText,
            providerMetadata: $providerMetadata,
        );
    }

    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public static function fallback(
        ?string $bookingUrl,
        ?CarbonImmutable $pulledAt = null,
        ?string $fallbackReason = null,
        ?string $statusText = null,
        array $providerMetadata = [],
    ): self {
        return new self(
            bookingUrl: $bookingUrl,
            availabilityPulledAt: $pulledAt,
            statusText: $statusText,
            fallbackReason: $fallbackReason,
            providerMetadata: $providerMetadata,
        );
    }

    public function availabilityPulledAtDisplay(): ?string
    {
        return $this->availabilityPulledAt?->timezone('America/Los_Angeles')->format('M j, Y \a\t g:i A T');
    }

    public function departureAtDisplay(): ?string
    {
        return $this->departureAt?->timezone('America/Los_Angeles')->format('D, M j, Y \a\t g:i A T');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'booking_url' => $this->bookingUrl,
            'provider_trip_id' => $this->providerTripId,
            'departure_at' => $this->departureAt?->toIso8601String(),
            'departure_at_display' => $this->departureAtDisplay(),
            'is_direct_booking' => $this->isDirectBooking,
            'open_spots' => $this->openSpots,
            'availability_pulled_at' => $this->availabilityPulledAt?->toIso8601String(),
            'availability_pulled_at_display' => $this->availabilityPulledAtDisplay(),
            'status_text' => $this->statusText,
            'fallback_reason' => $this->fallbackReason,
            'provider_metadata' => $this->providerMetadata,
        ];
    }
}
