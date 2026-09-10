<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\RawPayloadData;
use App\Enums\SourceType;
use App\Models\Boat;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Models\TripType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TripReportNormalizer
{
    private const SPORTFISHING_REPORT_SOURCE_SLUG = 'sportfishingreport_landing_pages';

    private const HALF_DAY_TRIP_TYPES = ['1/2 Day', '1/2 Day AM', '1/2 Day PM', '1/2 Day Twilight'];

    public function __construct(
        private readonly AliasNormalizer $normalizer,
        private readonly ParsedReportValidator $validator,
        private readonly ParserDiagnosticSynchronizer $diagnosticSynchronizer,
    ) {}

    /** @param null|array<int, ParserDiagnosticData> $diagnostics */
    public function replaceForPayload(RawScrapePayload $payload, ParsedFishCountCollection $parsed, ?array $diagnostics = null): int
    {
        $payload->loadMissing('scrapeSource');
        $diagnostics ??= $this->validator->validate(
            $payload,
            new RawPayloadData(
                sourceKey: $payload->scrapeSource->slug,
                targetDate: CarbonImmutable::parse($payload->target_date),
                url: $payload->url,
                body: $payload->payload,
                metadata: $payload->metadata ?? [],
            ),
            $parsed,
        );

        return DB::transaction(function () use ($payload, $parsed, $diagnostics): int {
            $this->diagnosticSynchronizer->sync($payload, $diagnostics);

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
                $boat = $report->canonicalBoatId === null
                    ? $this->normalizer->boat($report->boatName, $landing, $payload)
                    : Boat::query()->whereKey($report->canonicalBoatId)->where('is_active', true)->first();
                $tripType = $report->canonicalTripTypeId === null
                    ? $this->normalizer->tripType($report->tripTypeName, $payload)
                    : TripType::query()->whereKey($report->canonicalTripTypeId)->where('is_active', true)->first();
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
                'parser_version' => $parsed->tripReports->first()?->metadata['parser'] ?? $parsed->parserVersion ?? 'unknown',
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
        if ($boatId === null || $tripTypeId === null || $this->isHalfDayTripType($tripTypeId)) {
            return false;
        }

        return TripReport::query()
            ->whereDate('trip_date', $date)
            ->where('boat_id', $boatId)
            ->where('trip_type_id', $tripTypeId)
            ->whereHas('source', fn ($query) => $query->where('source_type', SourceType::Landing->value))
            ->exists();
    }

    private function deleteSportfishingReportFallbackReports(string $date, ?int $boatId, ?int $tripTypeId): void
    {
        if ($boatId === null || $tripTypeId === null || $this->isHalfDayTripType($tripTypeId)) {
            return;
        }

        TripReport::query()
            ->whereDate('trip_date', $date)
            ->where('boat_id', $boatId)
            ->where('trip_type_id', $tripTypeId)
            ->whereHas('source', fn ($query) => $query->where('slug', self::SPORTFISHING_REPORT_SOURCE_SLUG))
            ->with('speciesCounts')
            ->get()
            ->each(function (TripReport $tripReport): void {
                $this->deleteTripReport($tripReport);
            });
    }

    private function isHalfDayTripType(int $tripTypeId): bool
    {
        return TripType::query()->whereKey($tripTypeId)->whereIn('name', self::HALF_DAY_TRIP_TYPES)->exists();
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
        foreach (array_unique(array_filter($dates)) as $date) {
            DB::transaction(function () use ($date): void {
                $reports = $this->reportsForDate($date, lock: true);
                $primaryReportIds = $this->selectPrimaryReportIds($reports);

                $reports->pluck('id')->chunk(1000)->each(function (Collection $ids): void {
                    TripReport::query()->whereKey($ids->all())->update(['is_deduped_primary' => false]);
                });
                collect($primaryReportIds)->chunk(1000)->each(function (Collection $ids): void {
                    TripReport::query()->whereKey($ids->all())->update(['is_deduped_primary' => true]);
                });
            }, attempts: 3);
        }
    }

    /** @return array<int, int> */
    public function previewPrimaryReportIds(string $date): array
    {
        return $this->selectPrimaryReportIds($this->reportsForDate($date));
    }

    /** @return Collection<int, TripReport> */
    private function reportsForDate(string $date, bool $lock = false): Collection
    {
        return TripReport::query()
            ->whereDate('trip_date', $date)
            ->with(['source', 'tripType', 'speciesCounts'])
            ->orderByDesc('source_confidence')
            ->orderBy('source_id')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    /**
     * @param  Collection<int, TripReport>  $reports
     * @return array<int, int>
     */
    private function selectPrimaryReportIds(Collection $reports): array
    {
        [$halfDayReports, $otherReports] = $reports->partition(
            fn (TripReport $report): bool => in_array($report->tripType?->name, self::HALF_DAY_TRIP_TYPES, true),
        );
        $duplicateIds = [];
        $groups = $halfDayReports
            ->filter(fn (TripReport $report): bool => $report->boat_id !== null && $report->landing_id !== null
                && $report->speciesCounts->contains(fn (SpeciesCount $count): bool => $count->count > 0 || $count->released_count > 0))
            ->groupBy(fn (TripReport $report): string => $report->boat_id.'|'.$report->landing_id.'|'.$this->catchSignature($report));

        foreach ($groups as $group) {
            $directReports = $group->filter(fn (TripReport $report): bool => $report->source->source_type === SourceType::Landing);
            $fallbackReports = $group->filter(fn (TripReport $report): bool => $this->isSportfishingReportFallbackSource($report->source));
            $matches = [];

            foreach ($fallbackReports as $fallback) {
                $matches[$fallback->id] = $directReports
                    ->filter(fn (TripReport $direct): bool => ($direct->trip_type_id === $fallback->trip_type_id
                        || $direct->tripType->name === '1/2 Day' || $fallback->tripType->name === '1/2 Day')
                        && ($direct->anglers === null || $fallback->anglers === null || $direct->anglers === $fallback->anglers))
                    ->pluck('id')->all();
            }

            $directMatchCounts = array_count_values(array_merge(...array_values($matches)));
            foreach ($matches as $fallbackId => $directIds) {
                if (count($directIds) === 1 && $directMatchCounts[$directIds[0]] === 1) {
                    $duplicateIds[] = $fallbackId;
                }
            }
        }

        return $otherReports->unique('dedupe_key')->pluck('id')
            ->merge($halfDayReports->whereNotIn('id', $duplicateIds)->pluck('id'))->values()->all();
    }

    private function catchSignature(TripReport $report): string
    {
        return $report->speciesCounts
            ->map(fn (SpeciesCount $count): string => implode(':', [
                $count->species_id, (int) $count->is_retained_count, $count->count, $count->released_count,
            ]))
            ->sort()->implode('|');
    }

    private function storeSpeciesCount(RawScrapePayload $payload, TripReport $tripReport, ParsedSpeciesCountData $speciesCount): void
    {
        $species = $speciesCount->canonicalSpeciesId === null
            ? $this->normalizer->species($speciesCount->speciesName, $payload)
            : Species::query()->whereKey($speciesCount->canonicalSpeciesId)->where('is_active', true)->first();

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
