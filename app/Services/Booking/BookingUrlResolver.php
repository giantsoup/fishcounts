<?php

namespace App\Services\Booking;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use Illuminate\Support\Uri;

class BookingUrlResolver
{
    public function resolve(?Boat $boat, ?Landing $landing = null, ?string $sourceUrl = null): ?string
    {
        if (filled($boat?->booking_url)) {
            return $boat->booking_url;
        }

        $landing ??= $boat?->landing;

        if ($landing?->booking_provider === BookingProvider::FishingReservations) {
            return $this->fishingReservationsUrl($landing, $boat)
                ?? $landing->website_url
                ?? $sourceUrl;
        }

        if ($landing?->booking_provider === BookingProvider::HmLanding && $boat !== null) {
            return $this->hmLandingBoatUrl($landing, $boat);
        }

        return $landing?->website_url ?? $sourceUrl;
    }

    private function fishingReservationsUrl(Landing $landing, ?Boat $boat): ?string
    {
        if (! filled($landing->booking_base_url)) {
            return null;
        }

        if (! filled($boat?->booking_provider_identifier)) {
            return $landing->booking_base_url;
        }

        return (string) Uri::of($landing->booking_base_url)
            ->pushOntoQuery('boat_filter', $boat->booking_provider_identifier);
    }

    private function hmLandingBoatUrl(Landing $landing, Boat $boat): string
    {
        $baseUrl = filled($landing->booking_base_url)
            ? $landing->booking_base_url
            : 'https://www.hmlanding.com';

        return (string) Uri::of($baseUrl)
            ->withPath('/boat/'.$boat->slug)
            ->withFragment('tab-open-trips');
    }
}
