<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedTripReportData;
use Illuminate\Support\Str;

final class ParsedCollectionComparator
{
    /** @return array{status: string, missing_from_ai: list<array<string, mixed>>, extra_in_ai: list<array<string, mixed>>, differences: list<array<string, mixed>>, summary: array<string, int>} */
    public function compare(ParsedFishCountCollection $ai, ParsedFishCountCollection $deterministic, array $catalog): array
    {
        $aiReports = $ai->tripReports->values();
        $unmatchedAi = $aiReports->keys()->flip()->map(fn (): bool => true);
        $missing = [];
        $differences = [];

        foreach ($deterministic->tripReports->values() as $deterministicIndex => $report) {
            $aiIndex = $this->matchingIndex($report, $aiReports->all(), $unmatchedAi->keys()->all(), $catalog);
            if ($aiIndex === null) {
                $missing[] = $this->identity($report, $deterministicIndex);

                continue;
            }

            $unmatchedAi->forget($aiIndex);
            $difference = $this->differences($aiReports[$aiIndex], $report, $catalog);
            if ($difference !== []) {
                $differences[] = [
                    'ai_index' => $aiIndex,
                    'deterministic_index' => $deterministicIndex,
                    'identity' => $this->identity($report, $deterministicIndex),
                    'fields' => $difference,
                ];
            }
        }

        $extra = $unmatchedAi->keys()
            ->map(fn (int $index): array => $this->identity($aiReports[$index], $index))
            ->values()->all();
        $status = $missing === [] && $extra === [] && $differences === [] ? 'match' : 'different';

        return [
            'status' => $status,
            'missing_from_ai' => $missing,
            'extra_in_ai' => $extra,
            'differences' => $differences,
            'summary' => [
                'missing_reports' => count($missing),
                'extra_reports' => count($extra),
                'different_reports' => count($differences),
            ],
        ];
    }

    /** @param list<ParsedTripReportData> $candidates @param list<int> $available */
    private function matchingIndex(ParsedTripReportData $report, array $candidates, array $available, array $catalog): ?int
    {
        $sourceIdentifier = $report->metadata['source_trip_identifier'] ?? null;
        if (is_string($sourceIdentifier) && $sourceIdentifier !== '') {
            foreach ($available as $index) {
                if (($candidates[$index]->metadata['source_trip_identifier'] ?? null) === $sourceIdentifier) {
                    return $index;
                }
            }
        }

        foreach ($available as $index) {
            if ($this->sameIdentity($report, $candidates[$index], $catalog, true)) {
                return $index;
            }
        }

        $withoutAnglers = array_values(array_filter(
            $available,
            fn (int $index): bool => $this->sameIdentity($report, $candidates[$index], $catalog, false),
        ));

        return count($withoutAnglers) === 1 ? $withoutAnglers[0] : null;
    }

    private function sameIdentity(
        ParsedTripReportData $left,
        ParsedTripReportData $right,
        array $catalog,
        bool $includeAnglers,
    ): bool {
        return $left->tripDate->isSameDay($right->tripDate)
            && $this->entityKey('boats', $left->canonicalBoatId, $left->boatName, $catalog)
                === $this->entityKey('boats', $right->canonicalBoatId, $right->boatName, $catalog)
            && $this->entityKey('trip_types', $left->canonicalTripTypeId, $left->tripTypeName, $catalog)
                === $this->entityKey('trip_types', $right->canonicalTripTypeId, $right->tripTypeName, $catalog)
            && (! $includeAnglers || $left->anglers === $right->anglers);
    }

    /** @return array<string, array{ai: mixed, deterministic: mixed}> */
    private function differences(ParsedTripReportData $ai, ParsedTripReportData $deterministic, array $catalog): array
    {
        $fields = [
            'boat' => [
                $this->entityKey('boats', $ai->canonicalBoatId, $ai->boatName, $catalog),
                $this->entityKey('boats', $deterministic->canonicalBoatId, $deterministic->boatName, $catalog),
            ],
            'landing' => [$ai->landingName, $deterministic->landingName],
            'trip_type' => [
                $this->entityKey('trip_types', $ai->canonicalTripTypeId, $ai->tripTypeName, $catalog),
                $this->entityKey('trip_types', $deterministic->canonicalTripTypeId, $deterministic->tripTypeName, $catalog),
            ],
            'anglers' => [$ai->anglers, $deterministic->anglers],
            'species_counts' => [$this->counts($ai, $catalog), $this->counts($deterministic, $catalog)],
        ];
        $differences = [];

        foreach ($fields as $field => [$aiValue, $deterministicValue]) {
            if ($aiValue !== $deterministicValue) {
                $differences[$field] = ['ai' => $aiValue, 'deterministic' => $deterministicValue];
            }
        }

        return $differences;
    }

    /** @return array<string, array{retained: int, released: int}> */
    private function counts(ParsedTripReportData $report, array $catalog): array
    {
        $counts = [];

        foreach ($report->speciesCounts as $count) {
            $key = $this->entityKey('species', $count->canonicalSpeciesId, $count->speciesName, $catalog);
            $counts[$key] ??= ['retained' => 0, 'released' => 0];
            $counts[$key]['retained'] += $count->count;
            $counts[$key]['released'] += $count->releasedCount;
        }

        ksort($counts);

        return $counts;
    }

    private function entityKey(string $catalogKey, ?int $canonicalId, ?string $name, array $catalog): string
    {
        if ($canonicalId !== null) {
            return "id:{$canonicalId}";
        }

        $normalized = $this->normalizeName($name);
        foreach ($catalog[$catalogKey] ?? [] as $entity) {
            $names = array_merge([(string) ($entity['name'] ?? '')], $entity['aliases'] ?? []);
            if (collect($names)->contains(fn (string $candidate): bool => $this->normalizeName($candidate) === $normalized)) {
                return 'id:'.$entity['id'];
            }
        }

        return "name:{$normalized}";
    }

    private function normalizeName(?string $name): string
    {
        return Str::of((string) $name)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    /** @return array<string, mixed> */
    private function identity(ParsedTripReportData $report, int $index): array
    {
        return [
            'index' => $index,
            'source_item_id' => $report->metadata['source_trip_identifier'] ?? null,
            'date' => $report->tripDate->toDateString(),
            'boat_id' => $report->canonicalBoatId,
            'boat' => $report->boatName,
            'trip_type_id' => $report->canonicalTripTypeId,
            'trip_type' => $report->tripTypeName,
            'anglers' => $report->anglers,
        ];
    }
}
