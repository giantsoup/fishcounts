<?php

namespace App\Services\Booking;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Throwable;

class FishingReservationsAvailabilityService
{
    /** @var array<string, Collection<int, FishingReservationsTripOption>> */
    private array $tripOptionsByUrl = [];

    /** @var array<string, CarbonImmutable> */
    private array $pulledAtByUrl = [];

    /** @var array<string, BookingAvailability> */
    private array $availabilityByRequest = [];

    public function __construct(
        private readonly BookingUrlResolver $bookingUrlResolver,
        private readonly FishingReservationsBoatIdentifierResolver $boatIdentifierResolver,
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

        $providerBoatIdentifier = $this->boatIdentifierResolver->resolve($boat, $landing);

        if ($providerBoatIdentifier === null) {
            return BookingAvailability::fallback(
                $this->genericProviderUrl($boat, $landing, $sourceUrl),
                fallbackReason: 'provider_boat_not_found',
            );
        }

        $filteredBookingUrl = $this->filteredBookingUrl($landing, $providerBoatIdentifier);
        $scheduleUrl = $this->scheduleUrl($filteredBookingUrl);
        $cacheKey = implode('|', [
            $boat?->getKey() ?? spl_object_id($boat),
            $landing?->getKey() ?? spl_object_id($landing),
            $targetDate->toDateString(),
            $this->normalizedText($preferredTripType),
            $providerBoatIdentifier,
            $scheduleUrl,
        ]);

        if (isset($this->availabilityByRequest[$cacheKey])) {
            return $this->availabilityByRequest[$cacheKey];
        }

        try {
            $options = $this->tripOptionsForUrl($scheduleUrl);
            $matched = $this->matchingOption($options, $targetDate, $preferredTripType);

            if ($matched !== null) {
                return $this->availabilityByRequest[$cacheKey] = BookingAvailability::direct(
                    bookingUrl: $matched->bookingUrl,
                    providerTripId: $matched->providerTripId,
                    departureAt: $matched->departAt,
                    openSpots: $matched->openSpots,
                    availabilityPulledAt: $matched->pulledAt,
                    statusText: $matched->statusText,
                    providerMetadata: [
                        'provider' => BookingProvider::FishingReservations->value,
                        'provider_boat_identifier' => $providerBoatIdentifier,
                        'load' => $matched->load,
                        'price_text' => $matched->priceText,
                        'departure_at' => $matched->departAt?->toIso8601String(),
                        'return_at' => $matched->returnAt?->toIso8601String(),
                        'trip_type_text' => $matched->tripTypeText,
                        'comments' => $matched->comments,
                    ],
                );
            }

            $pulledAt = $this->pulledAtByUrl[$scheduleUrl] ?? $options->first()?->pulledAt;

            return $this->availabilityByRequest[$cacheKey] = BookingAvailability::fallback(
                bookingUrl: $filteredBookingUrl,
                pulledAt: $pulledAt,
                fallbackReason: 'exact_trip_not_available',
                statusText: $this->statusForDate($options, $targetDate),
            );
        } catch (Throwable $throwable) {
            Log::warning('FishingReservations availability lookup failed.', [
                'boat_id' => $boat?->getKey(),
                'landing_id' => $landing?->getKey(),
                'target_date' => $targetDate->toDateString(),
                'error' => $throwable->getMessage(),
            ]);

            return $this->availabilityByRequest[$cacheKey] = BookingAvailability::fallback(
                bookingUrl: $filteredBookingUrl,
                fallbackReason: 'provider_request_failed',
            );
        }
    }

    /**
     * @return Collection<int, FishingReservationsTripOption>
     */
    public function parseTripOptions(string $html, string $baseUrl, CarbonImmutable $pulledAt): Collection
    {
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $xpath = new DOMXPath($document);
        $rows = $xpath->query('//tr[(.//*[@data-trip-id] or .//a[contains(@href, "trip_id=")]) and not(.//td[contains(concat(" ", normalize-space(@class), " "), " scale-group ")])]');
        $options = collect();

        foreach ($rows as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = $this->cells($row);
            $bookingUrl = $this->bookingUrl($xpath, $row, $baseUrl);
            $providerTripId = $this->providerTripId($xpath, $row, $bookingUrl);
            $departAt = $this->providerDate($this->fieldText($xpath, $row, 'trip-depart') ?? ($cells[1] ?? null));

            if ($providerTripId === null && $departAt === null) {
                continue;
            }

            $options->push(new FishingReservationsTripOption(
                providerTripId: $providerTripId,
                bookingUrl: $bookingUrl,
                isBookable: $bookingUrl !== null,
                departAt: $departAt,
                returnAt: $this->providerDate($this->fieldText($xpath, $row, 'trip-return') ?? ($cells[2] ?? null)),
                tripTypeText: $this->fieldText($xpath, $row, 'trip-info') ?? ($cells[0] ?? null),
                load: $this->integerFromText($this->fieldText($xpath, $row, 'trip-load') ?? ($cells[3] ?? null)),
                openSpots: $this->integerFromText($this->fieldText($xpath, $row, 'trip-spots') ?? ($cells[5] ?? null)),
                priceText: $this->fieldText($xpath, $row, 'trip-price') ?? ($cells[4] ?? null),
                statusText: $bookingUrl === null ? $this->statusText($row) : 'Bookable',
                comments: $this->comments($row),
                pulledAt: $pulledAt,
            ));
        }

        return $options->values();
    }

    private function canFetch(?Boat $boat, ?Landing $landing): bool
    {
        return $boat !== null
            && $landing?->booking_provider === BookingProvider::FishingReservations
            && filled($landing->booking_base_url);
    }

    private function filteredBookingUrl(Landing $landing, string $providerBoatIdentifier): string
    {
        return (string) Uri::of($landing->booking_base_url)
            ->pushOntoQuery('boat_filter', $providerBoatIdentifier);
    }

    private function scheduleUrl(string $filteredBookingUrl): string
    {
        return (string) Uri::of($filteredBookingUrl)->withQueryIfMissing(['mode' => 'table']);
    }

    private function genericProviderUrl(Boat $boat, Landing $landing, ?string $sourceUrl): ?string
    {
        return $boat->booking_url
            ?? $landing->booking_base_url
            ?? $landing->website_url
            ?? $sourceUrl;
    }

    /**
     * @return Collection<int, FishingReservationsTripOption>
     */
    private function tripOptionsForUrl(string $scheduleUrl): Collection
    {
        if (isset($this->tripOptionsByUrl[$scheduleUrl])) {
            return $this->tripOptionsByUrl[$scheduleUrl];
        }

        $pulledAt = CarbonImmutable::now();
        $this->pulledAtByUrl[$scheduleUrl] = $pulledAt;
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->retry([250, 750], throw: false)
            ->get($scheduleUrl);

        $response->throw();

        return $this->tripOptionsByUrl[$scheduleUrl] = $this->parseTripOptions(
            $response->body(),
            $scheduleUrl,
            $pulledAt,
        );
    }

    /**
     * @param  Collection<int, FishingReservationsTripOption>  $options
     */
    private function matchingOption(Collection $options, CarbonImmutable $targetDate, ?string $preferredTripType): ?FishingReservationsTripOption
    {
        $earliestDeparture = $this->earliestDeparture($targetDate);
        $bookableOptions = $options
            ->filter(fn (FishingReservationsTripOption $option): bool => $option->isBookable
                && $option->departAt?->greaterThanOrEqualTo($earliestDeparture))
            ->sortBy(fn (FishingReservationsTripOption $option): string => $option->departAt?->toDateTimeString() ?? '')
            ->values();

        if ($bookableOptions->isEmpty()) {
            return null;
        }

        $normalizedPreferredTripType = $this->normalizedText($preferredTripType);

        if ($normalizedPreferredTripType !== '') {
            $tripTypeMatch = $bookableOptions->first(fn (FishingReservationsTripOption $option): bool => str_contains(
                $this->normalizedText($option->tripTypeText),
                $normalizedPreferredTripType,
            ));

            $normalizedBaseTripType = $this->normalizedBaseTripType($preferredTripType);

            $tripTypeMatch ??= $normalizedBaseTripType === ''
                ? null
                : $bookableOptions->first(fn (FishingReservationsTripOption $option): bool => str_contains(
                    $this->normalizedBaseTripType($option->tripTypeText),
                    $normalizedBaseTripType,
                ));

            if ($tripTypeMatch !== null) {
                return $tripTypeMatch;
            }
        }

        return $bookableOptions->first();
    }

    private function earliestDeparture(CarbonImmutable $targetDate): CarbonImmutable
    {
        $now = CarbonImmutable::now('America/Los_Angeles');
        $targetDate = CarbonImmutable::parse($targetDate->toDateString(), 'America/Los_Angeles')->startOfDay();

        return $targetDate->greaterThan($now) ? $targetDate : $now;
    }

    /**
     * @param  Collection<int, FishingReservationsTripOption>  $options
     */
    private function statusForDate(Collection $options, CarbonImmutable $targetDate): ?string
    {
        return $options
            ->first(fn (FishingReservationsTripOption $option): bool => $option->departAt?->toDateString() === $targetDate->toDateString())
            ?->statusText;
    }

    /** @return array<int, string> */
    private function cells(DOMElement $row): array
    {
        $cells = [];

        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement && mb_strtolower($child->tagName) === 'td') {
                $cells[] = $this->cleanText($child->textContent);
            }
        }

        return $cells;
    }

    private function fieldText(DOMXPath $xpath, DOMElement $row, string $class): ?string
    {
        $node = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]', $row)?->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $text = $this->elementText($node);

        return $text === '' ? null : $text;
    }

    private function elementText(DOMElement $element): string
    {
        $html = $element->ownerDocument->saveHTML($element) ?: '';
        $html = preg_replace('/<br\s*\/?>/i', ' ', $html) ?? $html;

        return $this->cleanText(strip_tags($html));
    }

    private function bookingUrl(DOMXPath $xpath, DOMElement $row, string $baseUrl): ?string
    {
        $links = $xpath->query('.//a[contains(@href, "user.php?trip_id=")]', $row);
        $link = $links?->item(0);

        if (! $link instanceof DOMElement) {
            return null;
        }

        return $this->absoluteUrl($baseUrl, $link->getAttribute('href'));
    }

    private function providerTripId(DOMXPath $xpath, DOMElement $row, ?string $bookingUrl): ?string
    {
        $tripNode = $xpath->query('.//*[@data-trip-id]', $row)?->item(0);

        if ($tripNode instanceof DOMElement && filled($tripNode->getAttribute('data-trip-id'))) {
            return $tripNode->getAttribute('data-trip-id');
        }

        if ($bookingUrl !== null) {
            parse_str((string) parse_url($bookingUrl, PHP_URL_QUERY), $query);

            return isset($query['trip_id']) ? (string) $query['trip_id'] : null;
        }

        return null;
    }

    private function providerDate(?string $text): ?CarbonImmutable
    {
        if ($text === null || ! preg_match('/(\d{1,2})-(\d{1,2})-(\d{4})\s+(\d{1,2}:\d{2})\s*([AP]M)/i', $text, $matches)) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            '!n-j-Y g:i A',
            "{$matches[1]}-{$matches[2]}-{$matches[3]} {$matches[4]} ".mb_strtoupper($matches[5]),
            'America/Los_Angeles',
        ) ?: null;
    }

    private function integerFromText(?string $text): ?int
    {
        if ($text === null || ! preg_match('/\d+/', html_entity_decode($text, ENT_QUOTES | ENT_HTML5), $matches)) {
            return null;
        }

        return (int) $matches[0];
    }

    private function statusText(DOMElement $row): ?string
    {
        $text = $this->cleanText($row->textContent);

        return $text === '' ? null : Str::of($text)->limit(120)->toString();
    }

    private function comments(DOMElement $row): ?string
    {
        $sibling = $this->nextElementSibling($row);

        if (! $sibling instanceof DOMElement) {
            return null;
        }

        if (! $this->hasClass($sibling, 'scale-group')) {
            $scaleGroup = (new DOMXPath($sibling->ownerDocument))->query('.//*[contains(concat(" ", normalize-space(@class), " "), " scale-group ")]', $sibling)?->item(0);

            if (! $scaleGroup instanceof DOMElement) {
                return null;
            }

            $sibling = $scaleGroup;
        }

        $text = $this->cleanText($sibling->textContent);

        return $text === '' ? null : $text;
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return str_contains(' '.$element->getAttribute('class').' ', ' '.$class.' ');
    }

    private function nextElementSibling(DOMNode $node): ?DOMElement
    {
        $sibling = $node->nextSibling;

        while ($sibling !== null && ! $sibling instanceof DOMElement) {
            $sibling = $sibling->nextSibling;
        }

        return $sibling instanceof DOMElement ? $sibling : null;
    }

    private function absoluteUrl(string $baseUrl, string $href): string
    {
        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if ($host === null) {
            return $href;
        }

        if (Str::startsWith($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }

        $path = (string) (parse_url($baseUrl, PHP_URL_PATH) ?: '/');
        $directory = str_ends_with($path, '/')
            ? rtrim($path, '/')
            : rtrim(dirname($path), '/');

        if ($directory === '' || $directory === '.') {
            return "{$scheme}://{$host}/{$href}";
        }

        return "{$scheme}://{$host}{$directory}/{$href}";
    }

    private function normalizedText(?string $text): string
    {
        return Str::of($text ?? '')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function normalizedBaseTripType(?string $text): string
    {
        return Str::of($this->normalizedText($text))
            ->replace([
                'coronado islands',
                'offshore',
                'local',
                'passport required',
            ], ' ')
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
