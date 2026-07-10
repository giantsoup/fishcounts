<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\Enums\SourceType;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TripReportNormalizer
{
    private const SPORTFISHING_REPORT_SOURCE_SLUG = 'sportfishingreport_landing_pages';

    public function __construct(private readonly AliasNormalizer $normalizer) {}

    public function replaceForPayload(RawScrapePayload $payload, ParsedFishCountCollection $parsed): int
    {
        return DB::transaction(function () use ($payload, $parsed): int {
            ParserError::query()
                ->where('raw_scrape_payload_id', $payload->id)
                ->delete();

            TripReport::query()
                ->where('source_id', $payload->scrape_source_id)
                ->whereDate('trip_date', $payload->target_date)
                ->with('speciesCounts')
                ->get()
                ->each(function (TripReport $tripReport): void {
                    $this->deleteTripReport($tripReport);
                });

            $source = $payload->scrapeSource;
            $count = 0;

            foreach ($parsed->tripReports as $index => $report) {
                if ($report->boatName === null) {
                    continue;
                }

                $region = $this->normalizer->region($report->regionName);
                $landingName = $report->landingName ?? $this->landingNameFromSource($source);
                $landing = $this->normalizer->landing($landingName, $region);
                $boat = $this->normalizer->boat($report->boatName, $landing, $payload);
                $tripType = $this->normalizer->tripType($report->tripTypeName, $payload);
                $dedupeKey = $this->dedupeKey($report->tripDate->toDateString(), $boat?->name ?? $report->boatName, $report->tripTypeName, $report->anglers);

                if ($this->isSportfishingReportFallbackSource($source) && $this->directLandingReportExists($report->tripDate->toDateString(), $boat?->id, $tripType?->id)) {
                    continue;
                }

                if ($source->source_type === SourceType::Landing) {
                    $this->deleteSportfishingReportFallbackReports($report->tripDate->toDateString(), $boat?->id, $tripType?->id);
                }

                $tripReport = TripReport::query()->create([
                    'source_id' => $source->id,
                    'raw_scrape_payload_id' => $payload->id,
                    'region_id' => $region?->id,
                    'landing_id' => $landing?->id,
                    'boat_id' => $boat?->id,
                    'trip_type_id' => $tripType?->id,
                    'trip_date' => $report->tripDate,
                    'source_trip_identifier' => $report->metadata['source_trip_identifier'] ?? "{$payload->id}:{$index}:{$dedupeKey}",
                    'anglers' => $report->anglers,
                    'raw_boat_name' => $report->boatName,
                    'raw_landing_name' => $landingName,
                    'raw_trip_type' => $report->tripTypeName,
                    'raw_fish_count_text' => $report->rawFishCountText,
                    'dedupe_key' => $dedupeKey,
                    'source_confidence' => max(1, 100 - $source->priority),
                    'metadata' => $report->metadata,
                ]);

                foreach ($report->speciesCounts as $speciesCount) {
                    $this->storeSpeciesCount($payload, $tripReport, $speciesCount);
                }

                $count++;
            }

            $payload->update([
                'parsed_at' => now(),
                'parser_version' => $parsed->tripReports->first()?->metadata['parser'] ?? 'unknown',
            ]);

            return $count;
        });
    }

    private function landingNameFromSource(ScrapeSource $source): ?string
    {
        return $source->source_type === SourceType::Landing ? $source->name : null;
    }

    private function isSportfishingReportFallbackSource(ScrapeSource $source): bool
    {
        return $source->slug === self::SPORTFISHING_REPORT_SOURCE_SLUG;
    }

    private function directLandingReportExists(string $date, ?int $boatId, ?int $tripTypeId): bool
    {
        if ($boatId === null || $tripTypeId === null) {
            return false;
        }

        $query = TripReport::query()
            ->whereDate('trip_date', $date)
            ->where('boat_id', $boatId)
            ->whereHas('source', fn ($query) => $query->where('source_type', SourceType::Landing->value));

        return $this->whereMatchingTripType($query, $tripTypeId)->exists();
    }

    private function deleteSportfishingReportFallbackReports(string $date, ?int $boatId, ?int $tripTypeId): void
    {
        if ($boatId === null || $tripTypeId === null) {
            return;
        }

        $query = TripReport::query()
            ->whereDate('trip_date', $date)
            ->where('boat_id', $boatId)
            ->whereHas('source', fn ($query) => $query->where('slug', self::SPORTFISHING_REPORT_SOURCE_SLUG))
            ->with('speciesCounts')
            ->where(function ($query) use ($tripTypeId): void {
                $query->where('trip_type_id', $tripTypeId);

                if ($this->isHalfDayLandingVariant($tripTypeId)) {
                    $query->orWhereHas('tripType', fn ($tripTypeQuery) => $tripTypeQuery->where('name', '1/2 Day'));
                }
            });

        $query
            ->get()
            ->each(function (TripReport $tripReport): void {
                $this->deleteTripReport($tripReport);
            });
    }

    private function whereMatchingTripType(Builder $query, int $tripTypeId): Builder
    {
        return $query->where(function ($query) use ($tripTypeId): void {
            $query->where('trip_type_id', $tripTypeId);

            if ($this->isHalfDayFallbackTripType($tripTypeId)) {
                $query->orWhereHas('tripType', fn ($tripTypeQuery) => $tripTypeQuery->whereIn('name', ['1/2 Day AM', '1/2 Day PM']));
            }
        });
    }

    private function isHalfDayFallbackTripType(int $tripTypeId): bool
    {
        return DB::table('trip_types')
            ->where('id', $tripTypeId)
            ->where('name', '1/2 Day')
            ->exists();
    }

    private function isHalfDayLandingVariant(int $tripTypeId): bool
    {
        return DB::table('trip_types')
            ->where('id', $tripTypeId)
            ->whereIn('name', ['1/2 Day AM', '1/2 Day PM'])
            ->exists();
    }

    private function deleteTripReport(TripReport $tripReport): void
    {
        $tripReport->speciesCounts()->delete();
        $tripReport->delete();
    }

    public function refreshPrimaryReports(string $date): void
    {
        $this->refreshPrimaryReportsForDates([$date]);
    }

    /** @param  array<int, string>  $dates */
    public function refreshPrimaryReportsForDates(array $dates): void
    {
        $dates = collect($dates)->filter()->unique()->values();

        if ($dates->isEmpty()) {
            return;
        }

        $this->whereTripDates(TripReport::query(), $dates)
            ->update(['is_deduped_primary' => false]);

        $primaryReportIds = [];
        $previousGroup = null;

        foreach ($this->whereTripDates(TripReport::query(), $dates)
            ->select(['id', 'trip_date', 'dedupe_key'])
            ->orderBy('trip_date')
            ->orderBy('dedupe_key')
            ->orderByDesc('source_confidence')
            ->orderBy('source_id')
            ->orderBy('id')
            ->cursor() as $tripReport) {
            $group = $tripReport->trip_date->toDateString().'|'.$tripReport->dedupe_key;

            if ($group === $previousGroup) {
                continue;
            }

            $primaryReportIds[] = $tripReport->id;
            $previousGroup = $group;
        }

        collect($primaryReportIds)
            ->chunk(1000)
            ->each(fn ($ids) => TripReport::query()->whereKey($ids->all())->update(['is_deduped_primary' => true]));
    }

    /** @param  Collection<int, string>  $dates */
    private function whereTripDates(Builder $query, Collection $dates): Builder
    {
        return $query->where(function (Builder $query) use ($dates): void {
            $dates->each(function (string $date, int $index) use ($query): void {
                if ($index === 0) {
                    $query->whereDate('trip_date', $date);

                    return;
                }

                $query->orWhereDate('trip_date', $date);
            });
        });
    }

    private function storeSpeciesCount(RawScrapePayload $payload, TripReport $tripReport, ParsedSpeciesCountData $speciesCount): void
    {
        $species = $this->normalizer->species($speciesCount->speciesName, $payload);

        if ($species === null) {
            return;
        }

        $storedCount = SpeciesCount::query()->firstOrNew(
            [
                'trip_report_id' => $tripReport->id,
                'species_id' => $species->id,
                'is_retained_count' => true,
            ],
        );

        $storedCount->count = (int) $storedCount->count + $speciesCount->count;
        $storedCount->released_count = (int) $storedCount->released_count + $speciesCount->releasedCount;
        $storedCount->raw_species_name = collect([$storedCount->raw_species_name, $speciesCount->speciesName])
            ->filter()
            ->unique()
            ->implode(', ');
        $storedCount->raw_count_text = collect([$storedCount->raw_count_text, $speciesCount->rawText])
            ->filter()
            ->unique()
            ->implode(', ');
        $storedCount->save();
    }

    public function dedupeKey(string $date, ?string $boat, ?string $tripType, ?int $anglers): string
    {
        return Str::of(implode('|', [
            $date,
            Str::slug($boat ?: 'unknown-boat'),
            Str::slug($tripType ?: 'unknown-trip'),
            $anglers ?: 'unknown-anglers',
        ]))->lower()->toString();
    }
}
