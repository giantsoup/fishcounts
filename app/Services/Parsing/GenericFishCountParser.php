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

    public function parseLine(RawPayloadData $payload, string $line, string $parserVersion = 'generic-line-v2'): ?ParsedTripReportData
    {
        if ($this->isAggregateLine($line)) {
            return null;
        }

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
            metadata: ['parser' => $parserVersion],
        );
    }

    /** @return Collection<int, ParsedSpeciesCountData> */
    public function parseSpeciesCounts(string $line): Collection
    {
        $line = preg_replace_callback(
            '/(?<retained>\d+)\s+(?<species>[A-Za-z][A-Za-z\s.\-]{2,40}?)\s*\(\s*(?<released>\d+)\s+released\s*\)/i',
            fn (array $matches): string => "{$matches['retained']} {$matches['species']}, {$matches['released']} {$matches['species']} Released",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/(?<released>\d+)\s+(?<species>[A-Za-z][A-Za-z\s.\-]{2,40}?)\s*\(\s*released\s*\)/i',
            fn (array $matches): string => "{$matches['released']} {$matches['species']} Released",
            $line,
        ) ?? $line;

        $line = Str::of($line)
            ->replace("\u{00A0}", ' ')
            ->replaceMatches('/\bamd\b/i', 'and')
            ->replaceMatches('/\bBleufin\s+Tuna\b/i', 'Bluefin Tuna')
            ->replaceMatches('/\bC\s+Alico\s+Bass\b/i', 'Calico Bass')
            ->replaceMatches('/\([^)]*\b(?:lbs?|pounds?)\b[^)]*\)/i', '')
            ->replaceMatches('/\bMisc\.\s+/i', 'Misc ')
            ->replaceMatches('/\ball\s+over\s+\d+\s*(?:lbs?|pounds?)\b/i', '')
            ->replaceMatches('/\b(?:from|at|over)\s+(?:\d+\s*(?:-|to|\x{2013})\s*\d+|up to\s+\d+|\d+)\s*(?:lbs?|pounds?)\b/iu', '')
            ->replaceMatches('/\b(?:up to\s+)?\d+\s*(?:-|to|\x{2013})\s*\d+\s*(?:lbs?|pounds?)\b/iu', '')
            ->replaceMatches('/\bup to\s+\d+\s*(?:lbs?|pounds?)\b/i', '')
            ->replaceMatches('/\b\d+(?:\/\d+)?\s*oz\b/i', '')
            ->replaceMatches('/\bfor\s+\d+\s+(?:anglers?|people|passengers?)\s+on\s+day\s+\d+\s+of\s+(?:their\s+|a\s+|an\s+)?(?:(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*day|half\s+day|full\s+day|overnight|twilight)\s+(?:trip|charter)\b/i', '')
            ->replaceMatches('/\bfor\s+(?:their\s+|a\s+|an\s+)?(?:(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*day|half\s+day|full\s+day|overnight|twilight)\s+(?:private\s+)?(?:trip|charter)\s+for\s+\d+\s+(?:anglers?|people|passengers?)\b/i', '')
            ->replaceMatches('/\bfor\s+(?:their\s+|a\s+|an\s+)?(?:(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*day|half\s+day|full\s+day|overnight|twilight)\s+(?:private\s+)?(?:trip|charter)?\s+with\s+\d+\s+(?:anglers?|people|passengers?)\b(?:\s+aboard)?/i', '')
            ->replaceMatches('/\bfor\s+(?:their\s+|a\s+|an\s+)?(?:(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*day|half\s+day|full\s+day|overnight|twilight)\s+(?:private\s+)?(?:trip|charter)\b/i', '')
            ->replaceMatches('/\bfor their\s+[^,.]{1,40}?\s+with\s+\d+\s+anglers?\b[^,.]*/i', '')
            ->replaceMatches('/\bon\s+(?:their\s+|a\s+|an\s+)?(?:(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*day|half\s+day|full\s*day|overnight|twilight)\s+(?:trip|charter)\b(?:\s+(?:for|with)\s+\d+\s+(?:anglers?|people|passengers?))?/i', '')
            ->replaceMatches('/\b(?:from\s+)?(?:their\s+|a\s+|an\s+)?(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*day\s+(?:(?:trip|charter)\s+|today\s+)?(?:with|wth)\b/i', '')
            ->replaceMatches('/\bfor\s+(?:their\s+)?\d+\s+(?:anglers?|people|passengers?)\b(?:\s+on\s+(?:their\s+|a\s+|an\s+)?(?:(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*day|half\s+day|full\s+day|overnight|twilight)\s+(?:trip|charter))?/i', '')
            ->replaceMatches('/\bwith\s+\d+\s+anglers?\s+aboard\b/i', '')
            ->replaceMatches('/\b\d+\s+(?:anglers?|people|passengers?)\s+(?:returned\s+with|caught|landed|had)\b/i', '')
            ->replaceMatches('/\s+and\s+(?=\d+\s+)/i', ', ')
            ->toString();

        preg_match_all('/(?<![#\d\/.])(?<count>\d+)\s+(?<species>[A-Za-z][A-Za-z\s.\-]{2,40}?)(?:\s+(?<released>Released))?(?=\s*(?:,|\.|!|$)|\s+\d+\s+)/i', $line, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match): ParsedSpeciesCountData {
                $species = Str::of($match['species'])
                    ->replaceMatches('/^\s*(?:and|with)\s+/i', '')
                    ->replaceMatches('/\b(?:anglers?|people|passengers?|released|kept|aboard)\b/i', '')
                    ->squish()
                    ->title()
                    ->toString();
                $count = (int) $match['count'];
                $isReleased = isset($match['released']) && Str::lower($match['released']) === 'released';

                return new ParsedSpeciesCountData(
                    speciesName: $species,
                    count: $isReleased ? 0 : $count,
                    releasedCount: $isReleased ? $count : 0,
                    rawText: trim($match[0]),
                );
            })
            ->filter(fn (ParsedSpeciesCountData $count): bool => $count->speciesName !== '' && ! in_array(Str::lower($count->speciesName), [
                'lbs',
                'lb',
                'pound',
                'pounds',
                'day',
                'day trip',
                'day with',
                'boats',
                'trips',
                'anglers',
            ], true))
            ->groupBy(fn (ParsedSpeciesCountData $count): string => $count->speciesName)
            ->map(function (Collection $counts): ParsedSpeciesCountData {
                /** @var ParsedSpeciesCountData $first */
                $first = $counts->first();

                return new ParsedSpeciesCountData(
                    speciesName: $first->speciesName,
                    count: $counts->sum('count'),
                    releasedCount: $counts->sum('releasedCount'),
                    rawText: $counts->pluck('rawText')->implode(', '),
                );
            })
            ->values();
    }

    private function extractBeforeAnglers(string $line): ?string
    {
        $boatName = $this->extractNarrativeBoatName($line);

        if ($boatName !== null) {
            return $boatName;
        }

        $before = Str::before($line, preg_match('/\d+\s+(?:anglers?|people|passengers?)/i', $line, $matches) ? $matches[0] : '');
        $candidate = Str::of($before)->replaceMatches('/^\W+|\W+$/', '')->squish()->toString();

        return $candidate !== '' ? $candidate : null;
    }

    private function extractTripType(string $line): ?string
    {
        if (preg_match('/(?:\(|\b)(?<period>AM|PM)(?:\)|\s+trip\b)/i', $line, $matches)) {
            return '1/2 Day '.Str::upper($matches['period']);
        }

        if (preg_match('/\b(?<trip>\d+(?:\.\d+)?\s*Day(?:\s+(?:AM|PM))?|AM\s+Half\s+Day|PM\s+Half\s+Day|Half\s+Day|Full\s+Day(?:\s+[A-Za-z\s]+)?|Overnight|Twilight|Twiligiht|Twlight)\b/i', $line, $matches)) {
            return $this->normalizeTripType($matches['trip']);
        }

        foreach (['1/2 Day', '3/4 Day', 'Full Day', 'Overnight', '1.5 Day', '2 Day', 'Twilight'] as $tripType) {
            if (Str::contains($line, $tripType, true)) {
                return $tripType;
            }
        }

        return null;
    }

    private function extractNarrativeBoatName(string $line): ?string
    {
        $line = Str::of($line)->replace("\u{00A0}", ' ')->squish()->toString();

        foreach ([
            '/^(?:(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*)?(?:The\s+)?(?<boat>[A-Z][A-Za-z0-9 \'&.-]{1,50}?)\s+(?:AM|PM)\s+\d+\s+(?:anglers?|people|passengers?)\b/i',
            '/^(?:(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*)?(?:The\s+)?(?:(?:AM|PM)\s+)?(?<boat>[A-Z][A-Za-z0-9 \'&.-]{1,50}?)(?:\'s)?\s+(?:(?:\((?:AM|PM)\)|AM|PM|Twilight|Twiligiht|Twlight)(?:\s+trip)?(?:\s+last\s+night)?\s+)?(?:also\s+)?(?:just\s+)?(?:caught|returned|had|has|finished(?:\s+up)?|ended|called\s+in|checked\s+in)\b/i',
            '/\b(?<boat>[A-Z][A-Za-z0-9 \'&.-]{2,50}?)\s+\d\/\d\s+Day\b/',
        ] as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                return Str::of($matches['boat'])
                    ->replaceMatches('/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s+/i', '')
                    ->squish()
                    ->toString();
            }
        }

        return null;
    }

    private function normalizeTripType(string $tripType): string
    {
        $normalized = Str::of($tripType)->squish()->title()->toString();

        if (preg_match('/^(?<period>Am|Pm)\s+Half\s+Day$/', $normalized, $matches)) {
            return '1/2 Day '.Str::upper($matches['period']);
        }

        return Str::of($normalized)
            ->replaceMatches('/^(?:Twiligiht|Twlight)$/i', 'Twilight')
            ->replaceMatches('/\s+Trip\s+With$/i', '')
            ->replaceMatches('/\s+With$/i', '')
            ->replaceMatches('/\s+Trip$/i', '')
            ->toString();
    }

    private function isAggregateLine(string $line): bool
    {
        $normalized = Str::of($line)
            ->lower()
            ->squish()
            ->toString();

        return Str::contains($normalized, ['dock total', 'dock totals', 'all trips', 'total fish'])
            || preg_match('/\b\d+\s+trips?\s+(?:with|for)\s+\d+\s+(?:anglers?|people|passengers?)\b/i', $normalized) === 1;
    }
}
