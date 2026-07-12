<?php

namespace App\Services\Notifications;

use App\Models\AlertRule;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Services\Booking\BookingAvailabilityResolver;
use App\Services\Booking\BookingUrlResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TripDecisionBuilder
{
    public function __construct(
        private readonly SourceHighlightLinkBuilder $sourceHighlightLinkBuilder,
        private readonly BookingUrlResolver $bookingUrlResolver,
        private readonly BookingAvailabilityResolver $bookingAvailabilityResolver,
    ) {}

    /**
     * @return Collection<int, array{
     *     boat_name: string,
     *     landing_name: string,
     *     trip_type: string,
     *     trip_date: string,
     *     target_count: int,
     *     source_url: ?string,
     *     source_highlight_url: ?string,
     *     booking_url: ?string,
     *     booking_availability: array<string, mixed>,
     *     booking_provider_trip_id: ?string,
     *     booking_departure_at: ?string,
     *     booking_departure_at_display: ?string,
     *     booking_is_direct: bool,
     *     booking_open_spots: ?int,
     *     booking_availability_pulled_at: ?string,
     *     booking_availability_pulled_at_display: ?string,
     *     booking_status_text: ?string,
     *     booking_fallback_reason: ?string
     * }>
     */
    public function rankedTrips(AlertRule $rule, CarbonImmutable $from, CarbonImmutable $to, int $limit = 5): Collection
    {
        $rule->loadMissing(['species', 'regions:id', 'landings:id', 'boats:id', 'tripTypes:id']);

        return $this->filteredReports($rule)
            ->whereDate('trip_date', '>=', $from->toDateString())
            ->whereDate('trip_date', '<=', $to->toDateString())
            ->whereHas('speciesCounts', fn (Builder $query) => $query
                ->where('species_id', $rule->species_id)
                ->where('is_retained_count', true)
                ->where('count', '>', 0))
            ->with([
                'boat:id,landing_id,name,slug,booking_url,booking_provider_identifier',
                'landing:id,name,website_url,booking_provider,booking_base_url',
                'rawScrapePayload:id,url',
                'speciesCounts.species:id,name',
                'tripType:id,name',
            ])
            ->get()
            ->map(fn (TripReport $tripReport): array => $this->rowForReport($tripReport, $rule))
            ->sortBy([
                ['target_count', 'desc'],
                ['trip_date_sort', 'desc'],
                ['boat_name', 'asc'],
            ])
            ->take($limit)
            ->map(fn (array $row): array => collect($row)->except(['trip_date_sort'])->all())
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tripOptions
     * @return Collection<int, array<string, mixed>>
     */
    public function recommendedBoats(Collection $tripOptions, int $limit = 3): Collection
    {
        return $tripOptions
            ->filter(fn (array $trip): bool => filled($trip['booking_url'] ?? null))
            ->unique(fn (array $trip): string => $trip['boat_name'])
            ->take($limit)
            ->values();
    }

    /** @return Builder<TripReport> */
    private function filteredReports(AlertRule $rule): Builder
    {
        $query = TripReport::query()->where('is_deduped_primary', true);

        if ($rule->regions->isNotEmpty()) {
            $query->whereIn('region_id', $rule->regions->pluck('id'));
        }

        if ($rule->landings->isNotEmpty()) {
            $query->whereIn('landing_id', $rule->landings->pluck('id'));
        }

        if ($rule->boats->isNotEmpty()) {
            $query->whereIn('boat_id', $rule->boats->pluck('id'));
        }

        if ($rule->tripTypes->isNotEmpty()) {
            $query->whereIn('trip_type_id', $rule->tripTypes->pluck('id'));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowForReport(TripReport $tripReport, AlertRule $rule): array
    {
        $targetCount = $this->targetCount($tripReport, $rule);
        $sourceUrl = $tripReport->rawScrapePayload?->url;
        $boatName = $tripReport->boat?->name ?? $tripReport->raw_boat_name ?? 'Unknown boat';
        $landingName = $tripReport->landing?->name ?? $tripReport->raw_landing_name ?? 'Unknown landing';
        $availability = $this->bookingAvailabilityResolver->resolve(
            $tripReport->boat,
            $tripReport->landing,
            $tripReport->trip_date->toImmutable(),
            $tripReport->tripType?->name ?? $tripReport->raw_trip_type,
            $sourceUrl,
        );
        $bookingUrl = $availability->bookingUrl ?? $this->bookingUrlResolver->resolve($tripReport->boat, $tripReport->landing, $sourceUrl);

        return [
            'boat_name' => $boatName,
            'landing_name' => $landingName,
            'trip_type' => $tripReport->tripType?->name ?? $tripReport->raw_trip_type ?? 'Unknown trip',
            'trip_date' => $tripReport->trip_date->format('n/j/y'),
            'trip_date_sort' => $tripReport->trip_date->toDateString(),
            'target_count' => $targetCount,
            'source_url' => $sourceUrl,
            'source_highlight_url' => $this->sourceHighlightLinkBuilder->build(
                $sourceUrl,
                $boatName,
                $this->targetCountText($tripReport, $rule, $targetCount),
            ),
            'booking_url' => $bookingUrl,
            'booking_availability' => $availability->toArray(),
            'booking_provider_trip_id' => $availability->providerTripId,
            'booking_departure_at' => $availability->departureAt?->toIso8601String(),
            'booking_departure_at_display' => $availability->departureAtDisplay(),
            'booking_is_direct' => $availability->isDirectBooking,
            'booking_open_spots' => $availability->openSpots,
            'booking_availability_pulled_at' => $availability->availabilityPulledAt?->toIso8601String(),
            'booking_availability_pulled_at_display' => $availability->availabilityPulledAtDisplay(),
            'booking_status_text' => $availability->statusText,
            'booking_fallback_reason' => $availability->fallbackReason,
        ];
    }

    private function targetCount(TripReport $tripReport, AlertRule $rule): int
    {
        return (int) $tripReport->speciesCounts
            ->where('species_id', $rule->species_id)
            ->where('is_retained_count', true)
            ->sum('count');
    }

    private function targetCountText(TripReport $tripReport, AlertRule $rule, int $targetCount): ?string
    {
        /** @var SpeciesCount|null $speciesCount */
        $speciesCount = $tripReport->speciesCounts
            ->first(fn (SpeciesCount $speciesCount): bool => $speciesCount->species_id === $rule->species_id
                && $speciesCount->is_retained_count);

        if (filled($speciesCount?->raw_count_text)) {
            return str($speciesCount->raw_count_text)
                ->before(',')
                ->squish()
                ->toString();
        }

        if ($targetCount > 0 && filled($rule->species?->name)) {
            return $targetCount.' '.$rule->species->name;
        }

        return null;
    }
}
