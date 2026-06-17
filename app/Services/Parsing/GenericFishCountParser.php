<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GenericFishCountParser
{
    public function parse(RawPayloadData $payload): ParsedFishCountCollection
    {
        $text = html_entity_decode(strip_tags($payload->body, "\n"), ENT_QUOTES | ENT_HTML5);
        $lines = collect(preg_split('/\R+/', $text) ?: [])
            ->map(fn (string $line): string => trim(preg_replace('/\s+/', ' ', $line) ?? ''))
            ->filter()
            ->values();

        $reports = $lines
            ->map(fn (string $line): ?ParsedTripReportData => $this->parseLine($payload, $line))
            ->filter()
            ->values();

        return new ParsedFishCountCollection($reports);
    }

    private function parseLine(RawPayloadData $payload, string $line): ?ParsedTripReportData
    {
        if (! preg_match('/(?<anglers>\d+)\s+(?:anglers?|people|passengers?)\b/i', $line, $anglerMatches)) {
            return null;
        }

        $speciesCounts = $this->parseSpeciesCounts($line);

        if ($speciesCounts->isEmpty()) {
            return null;
        }

        $boatName = $this->extractBeforeAnglers($line);
        $tripTypeName = $this->extractTripType($line);

        return new ParsedTripReportData(
            sourceKey: $payload->sourceKey,
            tripDate: $payload->targetDate,
            regionName: 'San Diego',
            landingName: null,
            boatName: $boatName,
            tripTypeName: $tripTypeName,
            anglers: (int) $anglerMatches['anglers'],
            rawFishCountText: $line,
            speciesCounts: $speciesCounts->all(),
            metadata: ['parser' => 'generic-line-v1'],
        );
    }

    /** @return Collection<int, ParsedSpeciesCountData> */
    private function parseSpeciesCounts(string $line): Collection
    {
        preg_match_all('/(?<count>\d+)\s+(?<species>[A-Za-z][A-Za-z\s\-]{2,40})(?=,|\.|$|\s+\d+\s+)/', $line, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match): ParsedSpeciesCountData {
                $species = Str::of($match['species'])
                    ->replaceMatches('/\b(?:anglers?|people|passengers?|released|kept)\b/i', '')
                    ->squish()
                    ->title()
                    ->toString();

                return new ParsedSpeciesCountData(
                    speciesName: $species,
                    count: (int) $match['count'],
                    rawText: trim($match[0]),
                );
            })
            ->filter(fn (ParsedSpeciesCountData $count): bool => $count->speciesName !== '');
    }

    private function extractBeforeAnglers(string $line): ?string
    {
        $before = Str::before($line, preg_match('/\d+\s+(?:anglers?|people|passengers?)/i', $line, $matches) ? $matches[0] : '');
        $candidate = Str::of($before)->replaceMatches('/^\W+|\W+$/', '')->squish()->toString();

        return $candidate !== '' ? $candidate : null;
    }

    private function extractTripType(string $line): ?string
    {
        foreach (['1/2 Day', '3/4 Day', 'Full Day', 'Overnight', '1.5 Day', '2 Day', 'Twilight'] as $tripType) {
            if (Str::contains($line, $tripType, true)) {
                return $tripType;
            }
        }

        return null;
    }
}
