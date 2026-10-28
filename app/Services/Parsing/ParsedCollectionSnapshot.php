<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\Enums\CatchQuantityBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ParsedCollectionSnapshot
{
    /** @return array<string, mixed> */
    public function make(ParsedFishCountCollection $parsed): array
    {
        return [
            'snapshot_version' => 2,
            'parser_version' => $parsed->parserVersion,
            'format' => $parsed->format,
            'reports' => $parsed->tripReports->map(fn (ParsedTripReportData $report): array => [
                'source_key' => $report->sourceKey,
                'source_item_id' => $report->metadata['source_trip_identifier'] ?? null,
                'date' => $report->tripDate->toDateString(),
                'region' => $report->regionName,
                'landing' => $report->landingName,
                'boat' => $report->boatName,
                'boat_id' => $report->canonicalBoatId,
                'trip_type' => $report->tripTypeName,
                'trip_type_id' => $report->canonicalTripTypeId,
                'anglers' => $report->anglers,
                'raw_fish_count_text' => $report->rawFishCountText,
                'metadata' => $report->metadata,
                'species_counts' => collect($report->speciesCounts)
                    ->map(fn (ParsedSpeciesCountData $count): array => [
                        'species' => $count->speciesName,
                        'species_id' => $count->canonicalSpeciesId,
                        'retained' => $count->count,
                        'released' => $count->releasedCount,
                        'raw_text' => $count->rawText,
                        'quantity_basis' => $count->quantityBasis->value,
                        'is_limits' => $count->isLimits,
                        'evidence' => $count->evidence,
                    ])->sortBy(fn (array $count): string => (string) ($count['species_id'] ?? $count['species']))
                    ->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(array $snapshot): ParsedFishCountCollection
    {
        if (($snapshot['snapshot_version'] ?? 1) > 2) {
            throw new \UnexpectedValueException('Unsupported parser snapshot version.');
        }
        $reports = collect($snapshot['reports'] ?? [])->map(function (array $report): ParsedTripReportData {
            $speciesCounts = collect($report['species_counts'] ?? [])->map(
                fn (array $count): ParsedSpeciesCountData => new ParsedSpeciesCountData(
                    speciesName: (string) $count['species'],
                    count: $count['retained'] === null ? null : (int) $count['retained'],
                    releasedCount: (int) $count['released'],
                    rawText: isset($count['raw_text']) ? (string) $count['raw_text'] : null,
                    canonicalSpeciesId: isset($count['species_id']) ? (int) $count['species_id'] : null,
                    quantityBasis: CatchQuantityBasis::from($count['quantity_basis'] ?? 'legacy_unclassified'),
                    isLimits: (bool) ($count['is_limits'] ?? false),
                    evidence: $count['evidence'] ?? [],
                ),
            )->values()->all();

            return new ParsedTripReportData(
                sourceKey: (string) $report['source_key'],
                tripDate: CarbonImmutable::parse((string) $report['date']),
                regionName: isset($report['region']) ? (string) $report['region'] : null,
                landingName: isset($report['landing']) ? (string) $report['landing'] : null,
                boatName: isset($report['boat']) ? (string) $report['boat'] : null,
                tripTypeName: isset($report['trip_type']) ? (string) $report['trip_type'] : null,
                anglers: isset($report['anglers']) ? (int) $report['anglers'] : null,
                rawFishCountText: isset($report['raw_fish_count_text']) ? (string) $report['raw_fish_count_text'] : null,
                speciesCounts: $speciesCounts,
                metadata: is_array($report['metadata'] ?? null) ? $report['metadata'] : [],
                canonicalBoatId: isset($report['boat_id']) ? (int) $report['boat_id'] : null,
                canonicalTripTypeId: isset($report['trip_type_id']) ? (int) $report['trip_type_id'] : null,
            );
        });

        return new ParsedFishCountCollection(
            tripReports: new Collection($reports),
            parserVersion: isset($snapshot['parser_version']) ? (string) $snapshot['parser_version'] : null,
            format: isset($snapshot['format']) ? (string) $snapshot['format'] : null,
        );
    }
}
