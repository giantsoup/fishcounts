<?php

namespace App\Services\Booking;

use Carbon\CarbonImmutable;

class FishingReservationsTripOption
{
    public function __construct(
        public readonly ?string $providerTripId,
        public readonly ?string $bookingUrl,
        public readonly bool $isBookable,
        public readonly ?CarbonImmutable $departAt,
        public readonly ?CarbonImmutable $returnAt,
        public readonly ?string $tripTypeText,
        public readonly ?int $load,
        public readonly ?int $openSpots,
        public readonly ?string $priceText,
        public readonly ?string $statusText,
        public readonly ?string $comments,
        public readonly CarbonImmutable $pulledAt,
    ) {}
}
