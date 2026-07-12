<?php

namespace App\Services\Booking;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use JsonException;
use Throwable;

class HmLandingAvailabilityService
{
    private const SELLER_ID = '53e93b35ad2171ef768b4588';

    /** @var array<string, Collection<int, HmLandingTripOption>> */
    private array $tripOptionsByUrl = [];

    /** @var array<string, BookingAvailability> */
    private array $availabilityByRequest = [];

    /** @var array<string, CarbonImmutable> */
    private array $pulledAtByUrl = [];

    /** @var array<string, string> */
    private array $cacheUrlByBaseUrl = [];

    public function __construct(
        private readonly BookingUrlResolver $bookingUrlResolver,
    ) {}

    public function resolve(
        ?Boat $boat,
        ?Landing $landing,
        CarbonImmutable $targetDate,
        ?string $preferredTripType = null,
        ?string $sourceUrl = null,
    ): BookingAvailability {
        $landing ??= $boat?->landing;
        $fallbackUrl = $this->bookingUrlResolver->resolve($boat, $landing, $sourceUrl);

        if (! $this->canFetch($boat, $landing)) {
            return BookingAvailability::fallback($fallbackUrl, fallbackReason: 'provider_not_configured');
        }

        $cacheUrl = $this->cacheUrl($landing);
        $cacheKey = implode('|', [
            $boat?->getKey() ?? spl_object_id($boat),
            $landing?->getKey() ?? spl_object_id($landing),
            $targetDate->toDateString(),
            $this->normalizedText($preferredTripType),
            $cacheUrl,
        ]);

        if (isset($this->availabilityByRequest[$cacheKey])) {
            return $this->availabilityByRequest[$cacheKey];
        }

        try {
            $options = $this->tripOptionsForUrl($cacheUrl);
            $matched = $this->matchingOption($options, $boat, $targetDate, $preferredTripType);

            if ($matched !== null) {
                return $this->availabilityByRequest[$cacheKey] = new BookingAvailability(
                    bookingUrl: $fallbackUrl,
                    providerTripId: null,
                    departureAt: $matched->departAt,
                    isDirectBooking: false,
                    openSpots: $matched->openSpots,
                    availabilityPulledAt: $matched->pulledAt,
                    statusText: $matched->statusText,
                    fallbackReason: 'provider_page_only',
                    providerMetadata: $this->providerMetadata($matched),
                );
            }

            $sameDate = $this->sameDateOption($options, $boat, $targetDate);

            return $this->availabilityByRequest[$cacheKey] = BookingAvailability::fallback(
                bookingUrl: $fallbackUrl,
                pulledAt: $this->pulledAtByUrl[$cacheUrl] ?? $sameDate?->pulledAt,
                fallbackReason: 'exact_trip_not_available',
                statusText: $sameDate?->statusText,
                providerMetadata: $sameDate === null ? [] : $this->providerMetadata($sameDate),
            );
        } catch (Throwable $throwable) {
            Log::warning('H&M Landing availability lookup failed.', [
                'boat_id' => $boat?->getKey(),
                'landing_id' => $landing?->getKey(),
                'target_date' => $targetDate->toDateString(),
                'error' => $throwable->getMessage(),
            ]);

            return $this->availabilityByRequest[$cacheKey] = BookingAvailability::fallback(
                bookingUrl: $fallbackUrl,
                fallbackReason: 'provider_request_failed',
            );
        }
    }

    /**
     * @return Collection<int, HmLandingTripOption>
     *
     * @throws JsonException
     */
    public function parseTripOptions(string $jsonp, CarbonImmutable $pulledAt): Collection
    {
        $data = $this->decodeJsonp($jsonp);
        $experiences = collect($data['experiences'] ?? []);

        return collect($data['trips'] ?? [])
            ->map(function (array $trip) use ($experiences, $pulledAt): ?HmLandingTripOption {
                $experienceId = (string) ($trip['expId'] ?? '');

                if ($experienceId === '') {
                    return null;
                }

                $experience = $experiences->get($experienceId);

                if (! is_array($experience)) {
                    return null;
                }

                $tripTitle = $this->cleanText((string) ($experience['name'] ?? ''));
                $boatName = $this->boatNameFromExperience($tripTitle);

                if ($boatName === '' || $tripTitle === '') {
                    return null;
                }

                $departAt = $this->providerDate($trip['datetime'] ?? null);
                $returnAt = $departAt === null
                    ? null
                    : $departAt->addMinutes((int) ($experience['duration'] ?? 0));
                $arrivalDate = $this->arrivalDate($trip['date'] ?? null);
                $arrivalTime = (string) ($trip['time'] ?? '');
                $openSpots = $this->nullableInteger($trip['open_spots'] ?? null);
                $statusText = $this->statusText($departAt, $openSpots);

                return new HmLandingTripOption(
                    xolaExperienceId: $experienceId,
                    sellerId: self::SELLER_ID,
                    boatName: $boatName,
                    tripTitle: $tripTitle,
                    tripTypeText: $this->tripTypeText($experience),
                    arrivalDate: $arrivalDate,
                    arrivalTime: $arrivalTime,
                    departAt: $departAt,
                    returnAt: $returnAt,
                    openSpots: $openSpots,
                    reservedSpots: $this->nullableInteger($trip['reserved_spots'] ?? null),
                    price: $this->nullableFloat($trip['price'] ?? null),
                    note: filled($trip['note'] ?? null) ? $this->cleanText((string) $trip['note']) : null,
                    statusText: $statusText,
                    isBookable: $statusText === 'Bookable' && $arrivalDate !== '' && $arrivalTime !== '',
                    pulledAt: $pulledAt,
                );
            })
            ->filter()
            ->values();
    }

    private function canFetch(?Boat $boat, ?Landing $landing): bool
    {
        return $boat !== null
            && $landing?->booking_provider === BookingProvider::HmLanding
            && filled($landing->booking_base_url);
    }

    private function cacheUrl(Landing $landing): string
    {
        $baseCacheUrl = (string) Uri::of($landing->booking_base_url)
            ->withPath('/xolacache')
            ->withQuery(['callback' => 'JSON_CALLBACK']);

        return $this->cacheUrlByBaseUrl[$baseCacheUrl] ??= (string) Uri::of($landing->booking_base_url)
            ->withPath('/xolacache')
            ->withQuery([
                'callback' => 'JSON_CALLBACK',
                'nocache' => (string) CarbonImmutable::now()->getTimestamp(),
            ]);
    }

    /**
     * @return Collection<int, HmLandingTripOption>
     */
    private function tripOptionsForUrl(string $cacheUrl): Collection
    {
        if (isset($this->tripOptionsByUrl[$cacheUrl])) {
            return $this->tripOptionsByUrl[$cacheUrl];
        }

        $pulledAt = CarbonImmutable::now();
        $this->pulledAtByUrl[$cacheUrl] = $pulledAt;
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->retry([250, 750], throw: false)
            ->get($cacheUrl);

        $response->throw();

        return $this->tripOptionsByUrl[$cacheUrl] = $this->parseTripOptions($response->body(), $pulledAt);
    }

    /**
     * @param  Collection<int, HmLandingTripOption>  $options
     */
    private function matchingOption(Collection $options, Boat $boat, CarbonImmutable $targetDate, ?string $preferredTripType): ?HmLandingTripOption
    {
        $bookableOptions = $this->upcomingOptionsForBoat($options, $boat, $targetDate)
            ->filter(fn (HmLandingTripOption $option): bool => $option->isBookable)
            ->sortBy(fn (HmLandingTripOption $option): string => $option->departAt?->toDateTimeString() ?? '')
            ->values();

        if ($bookableOptions->isEmpty()) {
            return null;
        }

        $normalizedPreferredTripType = $this->normalizedText($preferredTripType);

        if ($normalizedPreferredTripType !== '') {
            $tripTypeMatch = $bookableOptions->first(fn (HmLandingTripOption $option): bool => str_contains(
                $this->normalizedText($option->tripTitle.' '.$option->tripTypeText),
                $normalizedPreferredTripType,
            ));

            if ($tripTypeMatch !== null) {
                return $tripTypeMatch;
            }
        }

        return $bookableOptions->first();
    }

    /**
     * @param  Collection<int, HmLandingTripOption>  $options
     * @return Collection<int, HmLandingTripOption>
     */
    private function upcomingOptionsForBoat(Collection $options, Boat $boat, CarbonImmutable $targetDate): Collection
    {
        $normalizedBoatName = $this->normalizedText($boat->name);
        $now = CarbonImmutable::now('America/Los_Angeles');
        $targetDate = CarbonImmutable::parse($targetDate->toDateString(), 'America/Los_Angeles')->startOfDay();
        $earliestDeparture = $targetDate->greaterThan($now) ? $targetDate : $now;

        return $options
            ->filter(fn (HmLandingTripOption $option): bool => $this->normalizedText($option->boatName) === $normalizedBoatName
                && $option->departAt?->greaterThanOrEqualTo($earliestDeparture))
            ->values();
    }

    /**
     * @param  Collection<int, HmLandingTripOption>  $options
     */
    private function sameDateOption(Collection $options, Boat $boat, CarbonImmutable $targetDate): ?HmLandingTripOption
    {
        return $this->optionsForBoatAndDate($options, $boat, $targetDate)
            ->sortBy(fn (HmLandingTripOption $option): string => $option->departAt?->toDateTimeString() ?? '')
            ->first();
    }

    /**
     * @param  Collection<int, HmLandingTripOption>  $options
     * @return Collection<int, HmLandingTripOption>
     */
    private function optionsForBoatAndDate(Collection $options, Boat $boat, CarbonImmutable $targetDate): Collection
    {
        $normalizedBoatName = $this->normalizedText($boat->name);

        return $options
            ->filter(fn (HmLandingTripOption $option): bool => $this->normalizedText($option->boatName) === $normalizedBoatName
                && $option->departAt?->timezone('America/Los_Angeles')->toDateString() === $targetDate->toDateString())
            ->values();
    }

    /** @return array<string, mixed> */
    private function providerMetadata(HmLandingTripOption $option): array
    {
        return [
            'provider' => BookingProvider::HmLanding->value,
            'xola_experience_id' => $option->xolaExperienceId,
            'seller_id' => $option->sellerId,
            'arrival' => $option->arrivalDate,
            'arrival_time' => $option->arrivalTime,
            'trip_title' => $option->tripTitle,
            'trip_type_text' => $option->tripTypeText,
            'boat_name' => $option->boatName,
            'open_spots' => $option->openSpots,
            'reserved_spots' => $option->reservedSpots,
            'capacity' => $option->capacity(),
            'price' => $option->price,
            'departure_at' => $option->departAt?->toIso8601String(),
            'return_at' => $option->returnAt?->toIso8601String(),
            'note' => $option->note,
            'status_text' => $option->statusText,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeJsonp(string $jsonp): array
    {
        $payload = trim($jsonp);

        if (Str::startsWith($payload, '{')) {
            return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        }

        if (! preg_match('/JSON_CALLBACK\((.*)\);?\s*$/s', $payload, $matches)) {
            throw new JsonException('Unable to decode H&M Landing JSONP payload.');
        }

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function providerDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->timezone('America/Los_Angeles');
    }

    private function arrivalDate(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return CarbonImmutable::parse($value)->timezone('America/Los_Angeles')->toDateString();
    }

    private function statusText(?CarbonImmutable $departAt, ?int $openSpots): string
    {
        if ($departAt !== null && CarbonImmutable::now('America/Los_Angeles')->greaterThan($departAt)) {
            return 'Departed';
        }

        if ($openSpots !== null && $openSpots <= 0) {
            return 'Sold Out';
        }

        if ($departAt !== null && CarbonImmutable::now('America/Los_Angeles')->diffInMinutes($departAt, false) < 30) {
            return 'Call to Book';
        }

        return 'Bookable';
    }

    private function boatNameFromExperience(string $experienceName): string
    {
        return Str::of($experienceName)->before('-')->squish()->toString();
    }

    /** @param  array<string, mixed>  $experience */
    private function tripTypeText(array $experience): ?string
    {
        $name = $this->cleanText((string) ($experience['name'] ?? ''));
        $parts = collect(explode('-', $name))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();

        if ($parts->count() > 1) {
            return $parts->slice(1)->implode(' - ');
        }

        return filled($experience['duration'] ?? null)
            ? $this->fractionDuration((int) $experience['duration'])
            : null;
    }

    private function fractionDuration(int $minutes): string
    {
        $hours = $minutes / 60;

        return match (true) {
            $hours <= 6 => '1/2 Day',
            $hours <= 9 => '3/4 Day',
            $hours <= 16 => 'Full Day',
            $hours <= 28 => 'Overnight',
            $hours <= 39 => '1.5 Day',
            $hours <= 51 => '2 Day',
            $hours <= 63 => '2.5 Day',
            $hours <= 76 => '3 Day',
            $hours <= 86 => '3.5 Day',
            $hours <= 100 => '4 Day',
            $hours <= 111 => '4.5 Day',
            $hours <= 123 => '5 Day',
            $hours <= 134 => '5.5 Day',
            default => round(($minutes / 60 / 24) / 0.25) * 0.25.' Days',
        };
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizedText(?string $text): string
    {
        return Str::of($text ?? '')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function cleanText(string $text): string
    {
        return Str::of(html_entity_decode($text, ENT_QUOTES | ENT_HTML5))
            ->replaceMatches('/\s+/', ' ')
            ->squish()
            ->toString();
    }
}
