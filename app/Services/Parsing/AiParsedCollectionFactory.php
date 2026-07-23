<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use App\Enums\SourceType;
use App\Models\RawScrapePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class AiParsedCollectionFactory
{
    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $catalog
     */
    public function make(
        RawScrapePayload $payload,
        RawPayloadData $rawPayload,
        string $document,
        array $result,
        array $catalog,
    ): ParsedFishCountCollection {
        $reports = $result['reports'] ?? null;
        if (! is_array($reports) || count($reports) > (int) config('fish.ai_parsing.limits.max_reports')) {
            throw new UnexpectedValueException('The AI parser returned an invalid number of reports.');
        }
        if ($reports === [] && ! $this->sourceAllowsEmptyResults($rawPayload->sourceKey)) {
            throw new UnexpectedValueException('The AI parser returned an invalid number of reports.');
        }

        $boats = collect($catalog['boats'] ?? [])->keyBy('id');
        $landings = collect($catalog['landings'] ?? [])->keyBy('id');
        $tripTypes = collect($catalog['trip_types'] ?? [])->keyBy('id');
        $species = collect($catalog['species'] ?? [])->keyBy('id');
        $identities = [];
        $sourceItems = [];
        $parsedReports = new Collection;

        foreach ($reports as $index => $report) {
            if (! is_array($report)) {
                throw new UnexpectedValueException("AI report [{$index}] was invalid.");
            }

            $this->assertExactKeys($report, [
                'source_item_id', 'evidence_spans', 'raw_boat_name', 'canonical_boat_id',
                'raw_landing_name', 'canonical_landing_id', 'raw_trip_type',
                'canonical_trip_type_id', 'anglers', 'raw_fish_count_text', 'species_counts',
            ], "report [{$index}]");
            $sourceItemId = $this->requiredString($report['source_item_id'], "report [{$index}] source item", 200);
            $sourceBlock = $this->sourceBlock($sourceItemId, $document, $index);
            $evidenceSpans = $this->evidenceSpans($report['evidence_spans'], $sourceBlock, "report [{$index}]", 1000, 4000);
            $evidence = implode(' … ', $evidenceSpans);
            $boat = $this->canonical($boats, $report['canonical_boat_id'], 'boat', $index);
            $providerLanding = $this->canonical($landings, $report['canonical_landing_id'], 'landing', $index);
            $rawBoatName = $this->requiredString($report['raw_boat_name'], "report [{$index}] boat name", 255);
            $rawLandingName = $this->nullableString($report['raw_landing_name']);
            $rawTripType = $this->nullableString($report['raw_trip_type']);
            $this->assertCanonicalName($boat, $rawBoatName, 'boat', $index);
            $sourceLanding = $payload->scrapeSource->source_type === SourceType::Landing
                ? $landings->first(fn (array $candidate): bool => Str::lower($candidate['name']) === Str::lower($payload->scrapeSource->name))
                : null;
            if ($rawLandingName !== null || ! is_array($sourceLanding)) {
                $this->assertCanonicalName($providerLanding, $rawLandingName, 'landing', $index);
            }
            if (is_array($sourceLanding) && is_array($providerLanding) && $sourceLanding['id'] !== $providerLanding['id']) {
                throw new UnexpectedValueException("AI report [{$index}] selected a landing outside the source.");
            }
            $landing = $sourceLanding ?? $providerLanding;
            if ($landing === null && $boat !== null) {
                $landing = $landings->get($boat['landing_id']);
            }
            $tripType = $this->canonical($tripTypes, $report['canonical_trip_type_id'], 'trip type', $index);
            $anglers = $report['anglers'];

            if ($anglers !== null && (! is_int($anglers) || $anglers < 0 || $anglers > (int) config('fish.ai_parsing.limits.max_anglers'))) {
                throw new UnexpectedValueException("AI report [{$index}] contained an invalid angler count.");
            }
            if (! $this->spansContainExactText($evidenceSpans, $rawBoatName)) {
                throw new UnexpectedValueException("AI report [{$index}] cited a boat outside its evidence.");
            }
            if ($rawTripType !== null && ! $this->spansContainExactText($evidenceSpans, $rawTripType)) {
                throw new UnexpectedValueException("AI report [{$index}] cited a trip type outside its evidence.");
            }
            if ($anglers !== null && ! $this->spansSupportAnglers($evidenceSpans, $anglers)) {
                throw new UnexpectedValueException("AI report [{$index}] cited an angler count outside its evidence.");
            }

            if ($boat !== null && $landing !== null && $boat['landing_id'] !== $landing['id']) {
                throw new UnexpectedValueException("AI report [{$index}] selected a boat outside its landing.");
            }
            if ($boat !== null && is_array($sourceLanding) && $boat['landing_id'] !== $sourceLanding['id']) {
                throw new UnexpectedValueException("AI report [{$index}] selected a boat outside the source landing.");
            }

            $speciesCounts = $this->speciesCounts($report['species_counts'], $species, $evidenceSpans, $sourceBlock, $index);
            $rawFishCountText = $this->requiredString($report['raw_fish_count_text'], "report [{$index}] fish count", 8000);
            if (! $this->spansContainExactText($evidenceSpans, $rawFishCountText)) {
                throw new UnexpectedValueException("AI report [{$index}] cited fabricated fish-count text.");
            }
            $boatName = $rawBoatName;
            $landingName = $landing['name'] ?? ($payload->scrapeSource->source_type === SourceType::Landing ? $payload->scrapeSource->name : null);
            $tripTypeName = $rawTripType ?? ($tripType['name'] ?? null);
            $normalizedSourceItemId = Str::lower($sourceItemId);
            if (isset($sourceItems[$normalizedSourceItemId])) {
                throw new UnexpectedValueException("AI report [{$index}] duplicated a source item.");
            }
            $sourceItems[$normalizedSourceItemId] = true;
            $identity = implode('|', [
                $normalizedSourceItemId,
                $boat['id'] ?? Str::lower((string) $boatName),
                $tripType['id'] ?? Str::lower((string) $tripTypeName),
                $anglers ?? 'null',
            ]);

            if (isset($identities[$identity])) {
                throw new UnexpectedValueException("AI report [{$index}] duplicated another report.");
            }
            $identities[$identity] = true;

            $parsedReports->push(new ParsedTripReportData(
                sourceKey: $rawPayload->sourceKey,
                tripDate: CarbonImmutable::parse($payload->target_date),
                regionName: $landing['region'] ?? null,
                landingName: $landingName,
                boatName: $boatName,
                tripTypeName: $tripTypeName,
                anglers: $anglers,
                rawFishCountText: $rawFishCountText,
                speciesCounts: $speciesCounts,
                metadata: [
                    'parser' => 'ai-primary-'.config('fish.ai_parsing.prompt_version'),
                    'format' => 'ai-structured-output',
                    'source_trip_identifier' => 'ai:'.hash('sha256', implode('|', [
                        $rawPayload->sourceKey,
                        $payload->target_date->toDateString(),
                        $normalizedSourceItemId,
                    ])),
                    'ai_source_item_id' => $sourceItemId,
                    'ai_evidence' => $evidence,
                    'ai_evidence_spans' => $evidenceSpans,
                ],
                canonicalBoatId: $boat['id'] ?? null,
                canonicalTripTypeId: $tripType['id'] ?? null,
            ));
        }

        return new ParsedFishCountCollection(
            tripReports: $parsedReports,
            parserVersion: 'ai-primary-'.config('fish.ai_parsing.prompt_version'),
            format: 'ai-structured-output',
        );
    }

    /**
     * @param  Collection<int|string, array<string, mixed>>  $catalog
     * @return array<string, mixed>|null
     */
    private function canonical(Collection $catalog, mixed $value, string $type, int $reportIndex): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || ! is_array($entity = $catalog->get($value))) {
            throw new UnexpectedValueException("AI report [{$reportIndex}] selected an inactive or unknown {$type} ID.");
        }

        return $entity;
    }

    /**
     * @param  Collection<int|string, array<string, mixed>>  $catalog
     * @return list<ParsedSpeciesCountData>
     */
    private function speciesCounts(
        mixed $values,
        Collection $catalog,
        array $reportEvidenceSpans,
        string $sourceBlock,
        int $reportIndex,
    ): array {
        if (! is_array($values) || count($values) > (int) config('fish.ai_parsing.limits.max_species_per_report')) {
            throw new UnexpectedValueException("AI report [{$reportIndex}] contained invalid species counts.");
        }

        $counts = [];
        $identities = [];
        foreach ($values as $speciesIndex => $value) {
            if (! is_array($value)) {
                throw new UnexpectedValueException("AI species count [{$reportIndex}:{$speciesIndex}] was invalid.");
            }
            $this->assertExactKeys($value, [
                'raw_species_name', 'canonical_species_id', 'retained_count', 'released_count', 'evidence_spans',
            ], "species count [{$reportIndex}:{$speciesIndex}]");
            $canonical = $this->canonical($catalog, $value['canonical_species_id'], 'species', $reportIndex);
            $name = $this->requiredString($value['raw_species_name'], "species count [{$reportIndex}:{$speciesIndex}] name", 200);
            $this->assertCanonicalName($canonical, $name, 'species', $reportIndex);
            $retained = $value['retained_count'];
            $released = $value['released_count'];
            $maximum = (int) config('fish.ai_parsing.limits.max_count');

            if (! is_int($retained) || ! is_int($released) || $retained < 0 || $released < 0 || $retained > $maximum || $released > $maximum) {
                throw new UnexpectedValueException("AI species count [{$reportIndex}:{$speciesIndex}] exceeded count bounds.");
            }

            $evidenceSpans = $this->evidenceSpans(
                $value['evidence_spans'],
                implode("\n", $reportEvidenceSpans),
                "species count [{$reportIndex}:{$speciesIndex}]",
                255,
                255,
            );
            $this->assertCountEvidence($sourceBlock, $name, $retained, $released, $reportIndex, $speciesIndex);
            $evidence = implode(' … ', $evidenceSpans);
            $identity = (string) ($canonical['id'] ?? Str::lower($name));
            if (isset($identities[$identity])) {
                throw new UnexpectedValueException("AI report [{$reportIndex}] contained a duplicate species.");
            }
            $identities[$identity] = true;
            $counts[] = new ParsedSpeciesCountData(
                speciesName: $name,
                count: $retained,
                releasedCount: $released,
                rawText: $evidence,
                canonicalSpeciesId: $canonical['id'] ?? null,
            );
        }

        return $counts;
    }

    /** @param array<string, mixed> $value */
    private function assertExactKeys(array $value, array $expected, string $context): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new UnexpectedValueException("The AI {$context} contained missing or additional fields.");
        }
    }

    /**
     * @return list<string>
     */
    private function evidenceSpans(
        mixed $value,
        string $document,
        string $context,
        int $maximumSpanLength,
        int $maximumCombinedLength,
    ): array {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 4) {
            throw new UnexpectedValueException("The AI {$context} cited invalid evidence spans.");
        }

        $spans = [];
        foreach ($value as $spanIndex => $span) {
            $span = $this->requiredString($span, "{$context} evidence span [{$spanIndex}]", $maximumSpanLength);
            if (! str_contains($document, $span)) {
                throw new UnexpectedValueException("The AI {$context} cited fabricated evidence.");
            }

            $spans[] = $span;
        }

        if (count(array_unique($spans)) !== count($spans)) {
            throw new UnexpectedValueException("The AI {$context} cited duplicate evidence spans.");
        }
        if (Str::length(implode(' … ', $spans)) > $maximumCombinedLength) {
            throw new UnexpectedValueException("The AI {$context} evidence was too long.");
        }

        return $spans;
    }

    private function assertCountEvidence(
        string $sourceBlock,
        string $speciesName,
        int $retained,
        int $released,
        int $reportIndex,
        int $speciesIndex,
    ): void {
        $speciesNames = [$speciesName];

        if ($retained > 0 && ! $this->textSupportsCount($sourceBlock, $speciesNames, $retained, false)) {
            throw new UnexpectedValueException("AI species count [{$reportIndex}:{$speciesIndex}] lacked retained-count evidence.");
        }
        if ($released > 0 && ! $this->textSupportsCount($sourceBlock, $speciesNames, $released, true)) {
            throw new UnexpectedValueException("AI species count [{$reportIndex}:{$speciesIndex}] lacked released-count evidence.");
        }
    }

    /** @param list<string> $speciesNames */
    private function textSupportsCount(string $text, array $speciesNames, int $count, bool $released): bool
    {
        $clauses = preg_split('/[,;|\n]+|\band\b(?=\s+\d)/iu', $text) ?: [$text];

        foreach ($clauses as $clause) {
            foreach ($speciesNames as $speciesName) {
                $tokens = preg_split('/[^\pL\pN]+/u', Str::lower($speciesName), flags: PREG_SPLIT_NO_EMPTY) ?: [];
                if ($tokens === []) {
                    continue;
                }

                $speciesPattern = implode('[^\pL\pN]+', array_map(
                    fn (string $token): string => preg_quote($token, '/'),
                    $tokens,
                ));
                $countPattern = '(?<![\d.])'.preg_quote((string) $count, '/').'(?![\d.])';
                $directPattern = $countPattern.'\)?\s+(?:of\s+)?'.$speciesPattern.'(?![\pL\pN])';
                $patterns = $released
                    ? [
                        '/'.$directPattern.'\s+(?:released|returned)\b/iu',
                        '/'.$countPattern.'\s+(?:released|returned)\s+'.$speciesPattern.'(?![\pL\pN])/iu',
                        '/'.$speciesPattern.'(?![\pL\pN])\s*\(\s*'.$countPattern.'\s+(?:released|returned)\b/iu',
                    ]
                    : [
                        '/'.$directPattern.'(?!\s+(?:released|returned)\b)(?!\s*\(\s*(?:released|returned)\b)/iu',
                    ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $clause) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function sourceBlock(string $sourceItemId, string $document, int $reportIndex): string
    {
        if (preg_match('/^(?<block>block:\d{4})(?:#\d+)?$/', Str::lower($sourceItemId), $matches) !== 1
            || preg_match('/^\['.preg_quote($matches['block'], '/').'\]\s*(?<text>.*)$/mu', $document, $blockMatch) !== 1) {
            throw new UnexpectedValueException("AI report [{$reportIndex}] referenced an unknown source block.");
        }

        return $blockMatch['text'];
    }

    private function sourceAllowsEmptyResults(string $sourceKey): bool
    {
        return in_array($sourceKey, (array) config('fish.ai_parsing.sources.allow_empty_results', []), true);
    }

    /**
     * @param  array<string, mixed>|null  $canonical
     */
    private function assertCanonicalName(?array $canonical, ?string $rawName, string $type, int $reportIndex): void
    {
        if ($canonical === null) {
            return;
        }
        if ($rawName === null) {
            throw new UnexpectedValueException("AI report [{$reportIndex}] omitted the raw {$type} name for its canonical ID.");
        }

        $validNames = [
            $canonical['name'] ?? null,
            ...($canonical['aliases'] ?? []),
        ];
        $normalizedRawName = $this->normalizedEntityName($rawName, $type);
        $matches = collect($validNames)
            ->filter(fn (mixed $validName): bool => is_string($validName))
            ->contains(fn (string $validName): bool => $this->normalizedEntityName($validName, $type) === $normalizedRawName);

        if (! $matches) {
            throw new UnexpectedValueException("AI report [{$reportIndex}] paired a raw {$type} name with the wrong canonical ID.");
        }
    }

    /** @param list<string> $spans */
    private function spansContainExactText(array $spans, string $text): bool
    {
        return collect($spans)->contains(fn (string $span): bool => str_contains($span, $text));
    }

    /** @param list<string> $spans */
    private function spansSupportAnglers(array $spans, int $anglers): bool
    {
        $count = preg_quote((string) $anglers, '/');

        return collect($spans)->contains(
            fn (string $span): bool => preg_match(
                '/(?<![\d.])'.$count.'(?![\d.])\s+anglers?\b'
                .'|\banglers?\s*[:=-]?\s*'.$count.'(?![\d.])'
                .'|(?:^|\t)'.$count.'(?:\t|$)/iu',
                $span,
            ) === 1,
        );
    }

    private function normalizedName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->squish()
            ->toString();
    }

    private function normalizedEntityName(string $name, string $type): string
    {
        $normalizedName = $this->normalizedName($name);

        if ($type !== 'boat') {
            return $normalizedName;
        }

        if (Str::startsWith($normalizedName, 'the ')) {
            $normalizedName = Str::after($normalizedName, 'the ');
        }

        return preg_replace('/\s+(?:am|pm)$/u', '', $normalizedName) ?? $normalizedName;
    }

    private function requiredString(mixed $value, string $context, int $maximumLength): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("The AI {$context} was empty.");
        }

        $value = trim($value);
        if (Str::length($value) > $maximumLength) {
            throw new UnexpectedValueException("The AI {$context} was too long.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('The AI parser returned an invalid entity name.');
        }

        $value = trim($value);
        if (Str::length($value) > 255) {
            throw new UnexpectedValueException('The AI parser returned an entity name that was too long.');
        }

        return $value === '' ? null : $value;
    }
}
