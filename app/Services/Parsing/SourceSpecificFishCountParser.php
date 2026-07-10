<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SourceSpecificFishCountParser
{
    public function __construct(private readonly GenericFishCountParser $genericParser) {}

    public function parse(RawPayloadData $payload): ParsedFishCountCollection
    {
        return match ($payload->sourceKey) {
            'fishermans_landing',
            'seaforth_landing',
            'hm_landing',
            'point_loma_sportfishing' => $this->parseLandingPayload($payload),
            'sportfishingreport_landing_pages' => $this->parseSportfishingReportPartyBoatScoresPayload($payload),
            'sandiego_fish_reports' => $this->parseReportFeedPayload($payload),
            default => $this->genericParser->parse($payload),
        };
    }

    private function parseLandingPayload(RawPayloadData $payload): ParsedFishCountCollection
    {
        return $this->parseStructuredPayload($payload, "source-specific-{$payload->sourceKey}-v2");
    }

    private function parseReportFeedPayload(RawPayloadData $payload): ParsedFishCountCollection
    {
        return $this->parseStructuredPayload($payload, "source-specific-{$payload->sourceKey}-v2");
    }

    private function parseSportfishingReportPartyBoatScoresPayload(RawPayloadData $payload): ParsedFishCountCollection
    {
        $parserVersion = 'source-specific-sportfishingreport-party-boat-scores-v2';
        $panelHtml = $this->sportfishingReportSanDiegoPanelHtml($payload->body);

        if ($panelHtml === null) {
            return new ParsedFishCountCollection(collect());
        }

        preg_match_all('/<div\b(?=[^>]*border-top:\s*1px\s+solid\s+#dedede)[^>]*>(?<row>.*?)(?=<div\b(?=[^>]*border-top:\s*1px\s+solid\s+#dedede)|<\/table>|<\/div>\s*<br><br>|\z)/is', $panelHtml, $rowMatches);

        $reports = collect($rowMatches['row'] ?? [])
            ->map(fn (string $rowHtml): ?ParsedTripReportData => $this->sportfishingReportPartyBoatReportFromRow($payload, $rowHtml, $parserVersion))
            ->filter()
            ->values();

        return new ParsedFishCountCollection($reports);
    }

    private function sportfishingReportSanDiegoPanelHtml(string $html): ?string
    {
        if (! preg_match('/<div\b[^>]*class=[\'"][^\'"]*\bpanel\b[^\'"]*[\'"][^>]*>\s*<h2\b[^>]*>\s*San Diego Fish Counts\s*<\/h2>(?<panel>.*?)(?=<div\b[^>]*class=[\'"][^\'"]*\bpanel\b[^\'"]*[\'"]|<\/body>|\z)/is', $html, $matches)) {
            return null;
        }

        return $matches['panel'];
    }

    private function sportfishingReportPartyBoatReportFromRow(RawPayloadData $payload, string $rowHtml, string $parserVersion): ?ParsedTripReportData
    {
        preg_match_all('/<div\b[^>]*class=[\'"][^\'"]*col-[^\'"]*[\'"][^>]*>(?<cell>.*?)<\/div>/is', $rowHtml, $cellMatches);

        $cells = collect($cellMatches['cell'] ?? [])
            ->map(fn (string $cellHtml): string => $cellHtml)
            ->values();

        if ($cells->count() < 5) {
            return null;
        }

        $landingCell = (string) $cells->get(0);
        $anglers = $this->integerValue(['anglers' => $this->cleanHtmlText((string) $cells->get(1))], ['anglers']);
        $tripType = $this->cleanSportfishingReportTripType($this->cleanHtmlText((string) $cells->get(2)));
        $rawCounts = $this->cleanHtmlText((string) $cells->get(4));
        $speciesCounts = $this->speciesCountsFromRow([], $rawCounts);

        preg_match_all('/<a\b[^>]*>(?<text>.*?)<\/a>/is', $landingCell, $linkMatches);

        $linkTexts = collect($linkMatches['text'] ?? [])
            ->map(fn (string $linkHtml): string => $this->cleanHtmlText($linkHtml))
            ->filter(fn (string $text): bool => $text !== '')
            ->values();

        $boat = $linkTexts->get(0);
        $landing = $this->cleanLandingName($linkTexts->get(1));

        if ($speciesCounts->isEmpty() || ! is_string($boat) || $boat === '' || ! is_string($landing) || $landing === '') {
            return null;
        }

        return new ParsedTripReportData(
            sourceKey: $payload->sourceKey,
            tripDate: $payload->targetDate,
            regionName: 'San Diego',
            landingName: $landing,
            boatName: $boat,
            tripTypeName: $tripType,
            anglers: $anglers,
            rawFishCountText: $rawCounts,
            speciesCounts: $speciesCounts->all(),
            metadata: [
                'parser' => $parserVersion,
                'format' => 'party-boat-scores',
                'source_role' => 'fallback',
                'section' => 'San Diego Fish Counts',
            ],
        );
    }

    private function parseStructuredPayload(RawPayloadData $payload, string $parserVersion): ParsedFishCountCollection
    {
        $jsonReports = $this->parseJsonPayload($payload, $parserVersion);

        if ($jsonReports->isNotEmpty()) {
            return new ParsedFishCountCollection($jsonReports);
        }

        if ($payload->sourceKey === 'seaforth_landing') {
            $narrativeReports = $this->parseSeaforthNarrativePayload($payload, $parserVersion);

            if ($narrativeReports->isNotEmpty()) {
                return new ParsedFishCountCollection($narrativeReports);
            }
        }

        $tableReports = $this->parseHtmlTables($payload, $parserVersion);

        if ($tableReports->isNotEmpty()) {
            return new ParsedFishCountCollection($tableReports);
        }

        return new ParsedFishCountCollection(
            $this->genericParser->parse($payload)->tripReports
                ->map(function (ParsedTripReportData $report) use ($payload, $parserVersion): ParsedTripReportData {
                    return new ParsedTripReportData(
                        sourceKey: $report->sourceKey,
                        tripDate: $report->tripDate,
                        regionName: $report->regionName,
                        landingName: $report->landingName ?? $this->landingNameForSource($payload->sourceKey),
                        boatName: $report->boatName,
                        tripTypeName: $report->tripTypeName,
                        anglers: $report->anglers,
                        rawFishCountText: $report->rawFishCountText,
                        speciesCounts: $report->speciesCounts,
                        metadata: array_merge($report->metadata, ['parser' => $parserVersion, 'fallback_parser' => 'generic-line-v2']),
                    );
                }),
        );
    }

    /** @return Collection<int, ParsedTripReportData> */
    private function parseJsonPayload(RawPayloadData $payload, string $parserVersion): Collection
    {
        $decoded = json_decode($payload->body, true);

        if (! is_array($decoded)) {
            return collect();
        }

        $rows = Arr::isList($decoded) ? $decoded : Arr::first([
            Arr::get($decoded, 'counts'),
            Arr::get($decoded, 'reports'),
            Arr::get($decoded, 'data'),
            Arr::get($decoded, 'fish_counts'),
        ], fn ($candidate): bool => is_array($candidate)) ?? [];

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(fn (array $row): ?ParsedTripReportData => $this->reportFromRow($payload, $row, $parserVersion))
            ->filter()
            ->values();
    }

    /** @return Collection<int, ParsedTripReportData> */
    private function parseHtmlTables(RawPayloadData $payload, string $parserVersion): Collection
    {
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $payload->body, $rowMatches);

        $headers = collect();
        $reports = collect();

        foreach ($rowMatches[1] ?? [] as $rowHtml) {
            preg_match_all('/<t(?<type>[dh])\b[^>]*>(?<cell>.*?)<\/t[dh]>/is', $rowHtml, $cellMatches, PREG_SET_ORDER);

            $cells = collect($cellMatches)
                ->map(fn (array $match): array => [
                    'type' => $match['type'],
                    'text' => Str::of(html_entity_decode(strip_tags($match['cell']), ENT_QUOTES | ENT_HTML5))->squish()->toString(),
                ])
                ->filter(fn (array $cell): bool => $cell['text'] !== '')
                ->values();

            if ($cells->isEmpty()) {
                continue;
            }

            $headerCandidates = $cells
                ->filter(fn (array $cell): bool => $cell['type'] === 'h' || $this->headerKey($cell['text']) !== null)
                ->map(fn (array $cell): ?string => $this->headerKey($cell['text']))
                ->filter()
                ->values();

            $texts = $cells->pluck('text')->values();

            if (
                $headerCandidates->contains(fn (string $header): bool => in_array($header, ['boat', 'anglers', 'fish_counts'], true))
                && $this->genericParser->parseSpeciesCounts($texts->implode(', '))->isEmpty()
            ) {
                $headers = $headerCandidates->count() === $texts->count() && $headerCandidates->contains('anglers')
                    ? $headerCandidates
                    : collect();

                continue;
            }

            if ($texts->count() < 3 || $texts->contains(fn (string $text): bool => Str::contains(Str::lower($text), ['dock totals', 'fish counts for', 'san diego dock totals']))) {
                continue;
            }

            $row = $this->rowFromCells($texts, $headers);
            $report = $this->reportFromRow($payload, $row, $parserVersion);

            if ($report !== null) {
                $reports->push($report);
            }
        }

        return $reports->values();
    }

    /** @return Collection<int, ParsedTripReportData> */
    private function parseSeaforthNarrativePayload(RawPayloadData $payload, string $parserVersion): Collection
    {
        $listItemReports = $this->parseSeaforthListItems($payload, $parserVersion);

        if ($listItemReports->isNotEmpty()) {
            return $listItemReports;
        }

        $lines = collect(preg_split('/\R+/', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $payload->body)), ENT_QUOTES | ENT_HTML5)) ?: [])
            ->map(fn (string $line): string => Str::of($line)
                ->replace("\u{00A0}", ' ')
                ->squish()
                ->toString())
            ->filter(fn (string $line): bool => $line !== '')
            ->values();

        return $lines
            ->map(function (string $line, int $index) use ($lines, $payload, $parserVersion): ?ParsedTripReportData {
                if (! preg_match('/^(?<boat>[A-Z][A-Za-z0-9 \'&.-]{2,60}?)\s+(?<trip>(?:\d+(?:\.\d+)?|1\/2|3\/4)\s*Day|Half\s+Day|Full\s+Day|Overnight|Twilight)\s*$/i', $line, $matches)) {
                    return null;
                }

                $rawCounts = $lines->get($index + 1);

                if (! is_string($rawCounts)) {
                    return null;
                }

                $speciesCounts = $this->genericParser->parseSpeciesCounts($rawCounts);

                if ($speciesCounts->isEmpty()) {
                    return null;
                }

                return new ParsedTripReportData(
                    sourceKey: $payload->sourceKey,
                    tripDate: $payload->targetDate,
                    regionName: 'San Diego',
                    landingName: 'Seaforth Sportfishing',
                    boatName: Str::of($matches['boat'])->squish()->toString(),
                    tripTypeName: Str::of($matches['trip'])->squish()->title()->toString(),
                    anglers: null,
                    rawFishCountText: $rawCounts,
                    speciesCounts: $speciesCounts->all(),
                    metadata: ['parser' => $parserVersion, 'format' => 'narrative'],
                );
            })
            ->filter()
            ->values();
    }

    /** @return Collection<int, ParsedTripReportData> */
    private function parseSeaforthListItems(RawPayloadData $payload, string $parserVersion): Collection
    {
        preg_match_all('/<li\b[^>]*>(?<item>.*?)<\/li>/is', $payload->body, $matches);

        return collect($matches['item'] ?? [])
            ->flatMap(function (string $itemHtml) use ($payload, $parserVersion): Collection {
                $line = Str::of(html_entity_decode(strip_tags($itemHtml), ENT_QUOTES | ENT_HTML5))
                    ->replace("\u{00A0}", ' ')
                    ->squish()
                    ->toString();

                if (preg_match('/^The\s+(?<boat>[A-Z][A-Za-z0-9 \'&.-]{1,60}?)\s+continues\b.*?\bThe\s+finished\s+up\s+their\b/i', $line, $contextMatches)) {
                    $line = Str::of($line)
                        ->replaceFirst('The finished up their', "The {$contextMatches['boat']} finished up their")
                        ->toString();
                }

                preg_match_all($this->seaforthListItemPattern(), $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

                if ($matches === []) {
                    return collect();
                }

                return collect($matches)
                    ->map(function (array $match, int $index) use ($matches, $line, $payload, $parserVersion): ?ParsedTripReportData {
                        $startOffset = $match[0][1];
                        $endOffset = $matches[$index + 1][0][1] ?? strlen($line);
                        $reportText = Str::of($this->firstNarrativeSentence(substr($line, $startOffset, $endOffset - $startOffset)))
                            ->replaceMatches('/\s+(?=The\s+[A-Z])/', ' ')
                            ->squish()
                            ->toString();
                        $speciesCounts = $this->genericParser->parseSpeciesCounts($reportText);

                        if ($speciesCounts->isEmpty()) {
                            return null;
                        }

                        return new ParsedTripReportData(
                            sourceKey: $payload->sourceKey,
                            tripDate: $payload->targetDate,
                            regionName: 'San Diego',
                            landingName: 'Seaforth Sportfishing',
                            boatName: Str::of($match['boat'][0])->squish()->toString(),
                            tripTypeName: $this->normalizeSeaforthTripType(
                                $match['trip'][0] ?? $match['trip_alt'][0],
                                $match['period'][0] ?? null,
                            ),
                            anglers: null,
                            rawFishCountText: $reportText,
                            speciesCounts: $speciesCounts->all(),
                            metadata: ['parser' => $parserVersion, 'format' => 'narrative-list-item'],
                        );
                    })
                    ->filter()
                    ->values();
            })
            ->filter()
            ->values();
    }

    private function seaforthListItemPattern(): string
    {
        $tripPattern = '(?:\d+(?:\.\d+)?|1\/2|3\/4|One|Two|Three|Four)\s*Day|Half\s+Day|Full\s+Day\s+Coronado\s+Islands|Full\s+Day|Overnight|Twilight';
        $returnPhrase = '(?:also\s+)?(?:returned|ended)(?:\s+(?:this\s+(?:morning|afternoon|evening)|today))?\s+from\s+(?:a|their)\s+';
        $statusPhrase = '(?:(?:just\s+)?checked\s+in\s+from\s+their\s+|(?:finished(?:\s+up)?|ended)\s+their\s+|wrapped\s+up\s+today(?:\'s)?\s+|got\s+back\s+to\s+the\s+dock(?:\s+this\s+(?:morning|afternoon|evening))?\s+from\s+their\s+|'.$returnPhrase.')';
        $tripFirstQualifiers = '(?:(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:\s+(?:morning|afternoon|evening))?\s+)?(?:(?<period>AM|PM)\s+)?(?:local\s+|reverse\s+)?';
        $afterTripAction = '(?:(?:\s+[A-Za-z]+){0,3}\s+(?:trip|charter))?\s*(?:today\s+)?(?:(?:finished(?:\s+up)?|returned)(?:\s+from\s+their)?\s+)?';

        return '/(?:^|(?<=[.!?])\s+)The\s+(?<boat>[A-Z][A-Za-z0-9 \'&.-]{1,60}?)(?:\'s)?\s+(?:'
            .$statusPhrase.'(?:reverse\s+)?(?<trip>'.$tripPattern.')\s*'.$afterTripAction
            .'|'.$tripFirstQualifiers.'(?<trip_alt>'.$tripPattern.')\s*'.$afterTripAction
            .')(?:with|wth)\b/i';
    }

    private function normalizeSeaforthTripType(string $tripType, ?string $period): string
    {
        $normalized = Str::of($tripType)
            ->replaceMatches('/^One\s+Day$/i', '1 Day')
            ->replaceMatches('/^Two\s+Day$/i', '2 Day')
            ->replaceMatches('/^Three\s+Day$/i', '3 Day')
            ->replaceMatches('/^Four\s+Day$/i', '4 Day')
            ->replaceMatches('/^Half\s+Day$/i', '1/2 Day')
            ->squish()
            ->title()
            ->toString();

        return $period !== null
            ? $normalized.' '.Str::upper($period)
            : $normalized;
    }

    private function firstNarrativeSentence(string $text): string
    {
        $sentences = preg_split('/(?<!Misc\.)(?<=[.!?])\s+(?=[A-Z])/', $text, 2);

        return $sentences[0] ?? $text;
    }

    /** @param Collection<int, string> $cells */
    private function rowFromCells(Collection $cells, Collection $headers): array
    {
        if ($headers->isNotEmpty()) {
            $row = [];

            foreach ($cells as $index => $cell) {
                $header = $headers->get($index);

                if (is_string($header)) {
                    $row[$header] = $cell;
                }
            }

            if ($this->stringValue($row, ['fish_counts']) !== null) {
                return $row;
            }
        }

        if (
            is_string($cells->get(1))
            && is_string($cells->get(2))
            && preg_match('/\d+\s+boats?.*\d+\s+trips?/i', $cells->get(1))
            && preg_match('/\d+\s+anglers?/i', $cells->get(2))
        ) {
            return [
                'landing' => $cells->get(0),
                'anglers' => $cells->get(2),
                'fish_counts' => $cells->slice(3)->implode(', '),
            ];
        }

        return [
            'boat' => $cells->get(0),
            'trip_type' => $cells->get(1),
            'anglers' => $cells->get(2),
            'fish_counts' => $cells->slice(3)->implode(', '),
        ];
    }

    private function headerKey(string $header): ?string
    {
        $normalized = Str::of($header)->lower()->squish()->toString();

        return match (true) {
            Str::contains($normalized, 'landing') => 'landing',
            Str::contains($normalized, 'boat') => 'boat',
            Str::contains($normalized, ['trip type', 'trip']) => 'trip_type',
            Str::contains($normalized, ['angler', 'passenger', 'people']) => 'anglers',
            Str::contains($normalized, ['fish count', 'dock total', 'count']) => 'fish_counts',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function reportFromRow(RawPayloadData $payload, array $row, string $parserVersion): ?ParsedTripReportData
    {
        $boat = $this->stringValue($row, ['boat', 'boat_name', 'vessel']);
        $tripType = $this->stringValue($row, ['trip_type', 'trip', 'duration']);
        $landing = $this->cleanLandingName($this->stringValue($row, ['landing', 'landing_name'])) ?? $this->landingNameForSource($payload->sourceKey);
        $anglers = $this->integerValue($row, ['anglers', 'passengers', 'people']);
        $rawCounts = $this->stringValue($row, ['fish_counts', 'counts', 'catch', 'report']);
        $speciesCounts = $this->speciesCountsFromRow($row, $rawCounts);
        $isDockTotal = $landing !== null && $this->isAggregateBoatTripSummary($boat);

        if (
            $speciesCounts->isEmpty()
            || $boat === null
            || $isDockTotal
            || $this->isAggregateReportLabel($boat)
            || $this->isAggregateReportLabel($tripType)
            || $this->isSummaryRow($boat, $tripType, $landing)
        ) {
            return null;
        }

        $metadata = ['parser' => $parserVersion];

        return new ParsedTripReportData(
            sourceKey: $payload->sourceKey,
            tripDate: $payload->targetDate,
            regionName: 'San Diego',
            landingName: $landing,
            boatName: $boat,
            tripTypeName: $tripType,
            anglers: $anglers,
            rawFishCountText: $rawCounts,
            speciesCounts: $speciesCounts->all(),
            metadata: $metadata,
        );
    }

    private function isSummaryRow(?string $boat, ?string $tripType, ?string $landing): bool
    {
        foreach ([$boat, $tripType, $landing] as $value) {
            if (is_string($value) && preg_match('/^\d+\s+(?:boats?|trips?|anglers?)$/i', $value)) {
                return true;
            }
        }

        return false;
    }

    private function isAggregateBoatTripSummary(?string $value): bool
    {
        return is_string($value) && preg_match('/^\d+\s+boats?\s*.*\d+\s*trips?/i', $value) === 1;
    }

    private function isAggregateReportLabel(?string $value): bool
    {
        return is_string($value)
            && Str::of($value)
                ->lower()
                ->contains(['dock total', 'dock totals', 'all trips']);
    }

    /** @param array<string, mixed> $row */
    private function speciesCountsFromRow(array $row, ?string $rawCounts): Collection
    {
        $structuredCounts = Arr::get($row, 'species_counts', Arr::get($row, 'species'));

        if (is_array($structuredCounts)) {
            return collect($structuredCounts)
                ->map(function ($count, $species): ?ParsedSpeciesCountData {
                    if (is_array($count)) {
                        $species = $count['species'] ?? $count['name'] ?? $species;
                        $count = $count['count'] ?? $count['total'] ?? null;
                    }

                    if (! is_string($species) || ! is_numeric($count)) {
                        return null;
                    }

                    return new ParsedSpeciesCountData(
                        speciesName: Str::of($species)->squish()->title()->toString(),
                        count: (int) $count,
                        rawText: "{$count} {$species}",
                    );
                })
                ->filter()
                ->values();
        }

        return $rawCounts === null ? collect() : $this->genericParser->parseSpeciesCounts($rawCounts)->values();
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($row, $key);

            if (is_string($value) && trim($value) !== '') {
                return Str::of($value)->squish()->toString();
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function integerValue(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = Arr::get($row, $key);

            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_string($value) && preg_match('/\d+/', $value, $matches)) {
                return (int) $matches[0];
            }
        }

        return null;
    }

    private function landingNameForSource(string $sourceKey): ?string
    {
        return match ($sourceKey) {
            'fishermans_landing' => "Fisherman's Landing",
            'seaforth_landing' => 'Seaforth Sportfishing',
            'hm_landing' => 'H&M Landing',
            'point_loma_sportfishing' => 'Point Loma Sportfishing',
            default => null,
        };
    }

    private function cleanSportfishingReportTripType(string $tripType): string
    {
        return Str::of($tripType)
            ->replaceMatches('/\s+Trip$/i', '')
            ->squish()
            ->toString();
    }

    private function cleanHtmlText(string $html): string
    {
        return Str::of(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))
            ->replace("\u{00A0}", ' ')
            ->squish()
            ->toString();
    }

    private function cleanLandingName(?string $landing): ?string
    {
        if ($landing === null) {
            return null;
        }

        return Str::of($landing)
            ->replaceMatches('/\s+(?:San Diego|Oceanside),?\s+CA$/i', '')
            ->squish()
            ->toString();
    }
}
