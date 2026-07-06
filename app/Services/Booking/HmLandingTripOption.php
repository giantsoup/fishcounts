<?php

namespace App\Services\Booking;

use Carbon\CarbonImmutable;

class HmLandingTripOption
{
    public function __construct(
        public readonly string $xolaExperienceId,
        public readonly string $sellerId,
        public readonly string $boatName,
        public readonly string $tripTitle,
        public readonly ?string $tripTypeText,
        public readonly string $arrivalDate,
        public readonly string $arrivalTime,
        public readonly ?CarbonImmutable $departAt,
        public readonly ?CarbonImmutable $returnAt,
        public readonly ?int $openSpots,
        public readonly ?int $reservedSpots,
        public readonly ?float $price,
        public readonly ?string $note,
        public readonly string $statusText,
        public readonly bool $isBookable,
        public readonly CarbonImmutable $pulledAt,
    ) {}

    public function capacity(): ?int
    {
        if ($this->openSpots === null || $this->reservedSpots === null) {
            return null;
        }

        return $this->openSpots + $this->reservedSpots;
    }
}
