<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TripReportNormalizer
{
    public function __construct(private readonly AliasNormalizer $normalizer) {}

    public function replaceForPayload(RawScrapePayload $payload, ParsedFishCountCollection $parsed): int
    {
        return DB::transaction(function () use ($payload, $parsed): int {
            TripReport::query()
                ->where('source_id', $payload->scrape_source_id)
                ->whereDate('trip_date', $payload->target_date)
                ->with('speciesCounts')
                ->get()
                ->each(function (TripReport $tripReport): void {
                    $tripReport->speciesCounts()->delete();
                    $tripReport->delete();
                });

            $source = $payload->scrapeSource;
            $count = 0;

            foreach ($parsed->tripReports as $index => $report) {
                $region = $this->normalizer->region($report->regionName);
                $landing = $this->normalizer->landing($report->landingName, $region);
                $boat = $this->normalizer->boat($report->boatName, $landing);
                $tripType = $this->normalizer->tripType($report->tripTypeName, $payload);
                $dedupeKey = $this->dedupeKey($source, $report->tripDate->toDateString(), $report->boatName, $report->tripTypeName, $report->anglers);

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
                    'raw_landing_name' => $report->landingName,
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

    public function refreshPrimaryReports(string $date): void
    {
        TripReport::query()
            ->whereDate('trip_date', $date)
            ->update(['is_deduped_primary' => false]);

        TripReport::query()
            ->whereDate('trip_date', $date)
            ->orderByDesc('source_confidence')
            ->orderBy('source_id')
            ->orderBy('id')
            ->get()
            ->groupBy('dedupe_key')
            ->each(function ($reports): void {
                $reports->first()->update(['is_deduped_primary' => true]);
            });
    }

    private function storeSpeciesCount(RawScrapePayload $payload, TripReport $tripReport, ParsedSpeciesCountData $speciesCount): void
    {
        $species = $this->normalizer->species($speciesCount->speciesName, $payload);

        if ($species === null) {
            return;
        }

        SpeciesCount::query()->updateOrCreate(
            [
                'trip_report_id' => $tripReport->id,
                'species_id' => $species->id,
                'is_retained_count' => true,
            ],
            [
                'count' => $speciesCount->count,
                'released_count' => $speciesCount->releasedCount,
                'raw_species_name' => $speciesCount->speciesName,
                'raw_count_text' => $speciesCount->rawText,
            ],
        );
    }

    private function dedupeKey(ScrapeSource $source, string $date, ?string $boat, ?string $tripType, ?int $anglers): string
    {
        return Str::of(implode('|', [
            $date,
            Str::slug($boat ?: 'unknown-boat'),
            Str::slug($tripType ?: 'unknown-trip'),
            $anglers ?: 'unknown-anglers',
        ]))->lower()->toString();
    }
}
