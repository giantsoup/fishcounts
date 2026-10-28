<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\Enums\CatchQuantityBasis;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\RawScrapePayload;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class LimitsSourceReconciler
{
    public function __construct(private readonly RawPayloadEvaluator $evaluator, private readonly LimitsClaimExtractor $claims) {}

    public function fingerprint(RawScrapePayload $payload): string
    {
        return hash('sha256', $this->payloads($payload)->map(fn (RawScrapePayload $candidate): string => $candidate->id.':'.$candidate->payload_hash)->sort()->implode('|'));
    }

    public function reconcile(ParsedFishCountCollection $parsed, RawScrapePayload $payload): ParsedFishCountCollection
    {
        if (! config('limits.enabled') || ! $parsed->tripReports->contains(fn (ParsedTripReportData $report): bool => preg_match('/\blimits\b/i', $report->rawFishCountText ?? '') === 1)) {
            return $parsed;
        }
        $candidates = [];
        foreach ($this->payloads($payload) as $otherPayload) {
            try {
                [, , $otherParsed] = $this->evaluator->evaluate($otherPayload->id);
                foreach ($otherParsed->tripReports as $other) {
                    $candidates[] = ['report' => $other, 'payload' => $otherPayload];
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        if ($candidates === []) {
            return $parsed;
        }
        $identities = $this->identities([...$parsed->tripReports->all(), ...array_column($candidates, 'report')]);
        $matches = [];
        foreach ($parsed->tripReports as $index => $report) {
            $matches[$index] = array_keys(array_filter($candidates, fn (array $candidate): bool => $this->matches($report, $candidate['report'], $identities)));
        }
        $usages = array_count_values(array_merge([], ...array_values($matches)));
        $reports = $parsed->tripReports->map(function (ParsedTripReportData $report, int $index) use ($candidates, $matches, $usages, $identities): ParsedTripReportData {
            if (preg_match('/\blimits\b/i', $report->rawFishCountText ?? '') !== 1) {
                return $report;
            }
            if (count($matches[$index]) !== 1 || $usages[$matches[$index][0]] !== 1) {
                $conflicts = array_filter($candidates, fn (array $candidate): bool => $identities[spl_object_id($report)]['boat'] !== null && $identities[spl_object_id($report)]['boat'] === $identities[spl_object_id($candidate['report'])]['boat'] && $identities[spl_object_id($report)]['type'] !== null && $identities[spl_object_id($report)]['type'] === $identities[spl_object_id($candidate['report'])]['type']);
                if ($conflicts === []) {
                    return $report;
                }

                return new ParsedTripReportData($report->sourceKey, $report->tripDate, $report->regionName, $report->landingName, $report->boatName, $report->tripTypeName, $report->anglers, $report->rawFishCountText, $report->speciesCounts,
                    [...$report->metadata, 'limits_source_conflict' => ['reason' => count($matches[$index]) > 1 ? 'ambiguous_same_trip_sources' : 'conflicting_trip_evidence', 'payload_ids' => array_values(array_unique(array_map(fn (array $candidate): int => $candidate['payload']->id, $conflicts)))]], $report->canonicalBoatId, $report->canonicalTripTypeId);
            }
            $candidate = $candidates[$matches[$index][0]];
            $other = $candidate['report'];
            $counts = $report->speciesCounts;
            foreach ($this->claims->extract($report->rawFishCountText ?? '') as $claim) {
                if (! collect($counts)->contains(fn (ParsedSpeciesCountData $count): bool => $this->claims->identity($count->speciesName) === $this->claims->identity($claim->speciesName))) {
                    $counts[] = $claim;
                }
            }
            $counts = array_map(function (ParsedSpeciesCountData $count) use ($candidate, $other): ParsedSpeciesCountData {
                if ($count->count !== null || ($count->evidence['released_limits'] ?? false)) {
                    return $count;
                }
                $explicit = collect($other->speciesCounts)->filter(fn (ParsedSpeciesCountData $otherCount): bool => $otherCount->quantityBasis === CatchQuantityBasis::Explicit && $otherCount->count !== null
                    && $this->claims->identity($otherCount->speciesName) === $this->claims->identity($count->speciesName));
                if ($explicit->count() !== 1) {
                    return $count;
                }
                $evidence = $explicit->first();
                $hasRetainedEvidence = $evidence->count !== 0 || $this->claims->hasExplicitRetainedZero($evidence->rawText ?? '', $evidence->speciesName);

                $released = $count->releasedCount;
                $releaseEvidence = [];
                if ($released === 0 && $evidence->releasedCount > 0) {
                    $released = $evidence->releasedCount;
                    $releaseEvidence['corroborated_releases'] = ['count' => $released, 'payload_id' => $candidate['payload']->id, 'payload_hash' => $candidate['payload']->payload_hash, 'explicit_span' => $evidence->rawText, 'species' => $evidence->speciesName];
                }

                return new ParsedSpeciesCountData($count->speciesName, $hasRetainedEvidence ? $evidence->count : null, $released, $count->rawText, $count->canonicalSpeciesId ?? $evidence->canonicalSpeciesId, $hasRetainedEvidence ? CatchQuantityBasis::Corroborated : CatchQuantityBasis::LimitsUnquantified, true,
                    [...$count->evidence, ...$releaseEvidence, 'supporting_payload_id' => $candidate['payload']->id, 'supporting_payload_hash' => $candidate['payload']->payload_hash, 'explicit_span' => $evidence->rawText, 'supporting_species' => $evidence->speciesName]);
            }, $counts);
            $metadata = [...$report->metadata, 'corroborated_context' => ['payload_id' => $candidate['payload']->id, 'payload_hash' => $candidate['payload']->payload_hash, 'source_block' => $other->rawFishCountText, 'trip_type' => $other->tripTypeName]];
            if ($report->anglers === null && $other->anglers !== null) {
                $metadata['corroborated_anglers'] = ['count' => $other->anglers, 'payload_id' => $candidate['payload']->id, 'payload_hash' => $candidate['payload']->payload_hash, 'source_block' => $other->rawFishCountText];
            }

            return new ParsedTripReportData($report->sourceKey, $report->tripDate, $report->regionName, $report->landingName, $report->boatName, $report->tripTypeName, $report->anglers ?? $other->anglers, $report->rawFishCountText, $counts, $metadata, $report->canonicalBoatId, $report->canonicalTripTypeId);
        });

        return new ParsedFishCountCollection($reports, $parsed->parserVersion, $parsed->format);
    }

    /** @return Collection<int, RawScrapePayload> */
    private function payloads(RawScrapePayload $payload): Collection
    {
        return RawScrapePayload::query()->where('target_date', '>=', $payload->target_date->toDateString())->where('target_date', '<', $payload->target_date->copy()->addDay()->toDateString())->where('scrape_source_id', '!=', $payload->scrape_source_id)->orderByDesc('fetched_at')->orderByDesc('id')->get(['id', 'scrape_source_id', 'payload_hash'])->unique('scrape_source_id')->values();
    }

    /** @param array<int, array{boat: ?int, type: ?int, landing: ?int}> $identities */
    private function matches(ParsedTripReportData $left, ParsedTripReportData $right, array $identities): bool
    {
        $leftIdentity = $identities[spl_object_id($left)];
        $rightIdentity = $identities[spl_object_id($right)];
        $boat = $leftIdentity['boat'];
        $type = $leftIdentity['type'];
        if ($boat === null || $type === null || $boat !== $rightIdentity['boat'] || $type !== $rightIdentity['type']
            || ! $left->tripDate->isSameDay($right->tripDate)
            || ($left->anglers !== null && $right->anglers !== null && $left->anglers !== $right->anglers)) {
            return false;
        }
        $landing = $leftIdentity['landing'];
        if ($landing === null || ($left->landingName !== null && $right->landingName !== null && Str::lower($left->landingName) !== Str::lower($right->landingName))) {
            return false;
        }
        foreach (['am', 'pm', 'twilight'] as $period) {
            $pattern = '/\b'.$period.'\b/i';
            if ((preg_match($pattern, ($left->tripTypeName ?? '').' '.$left->boatName) === 1) !== (preg_match($pattern, ($right->tripTypeName ?? '').' '.$right->boatName) === 1)) {
                return false;
            }
        }
        $overlap = 0;
        foreach ($left->speciesCounts as $count) {
            $others = collect($right->speciesCounts)->filter(fn (ParsedSpeciesCountData $other): bool => $other->count !== null && $this->claims->identity($count->speciesName) === $this->claims->identity($other->speciesName));
            if ($others->isEmpty()) {
                continue;
            }
            if ($count->count === null) {
                if ($others->count() !== 1) {
                    return false;
                }
                $other = $others->first();
                $directReleaseKnown = $count->releasedCount > 0 || $this->claims->hasExplicitReleasedZero($left->rawFishCountText ?? '', $count->speciesName);
                $supportReleaseKnown = $other->releasedCount > 0 || $this->claims->hasExplicitReleasedZero($other->rawText ?? '', $other->speciesName);
                if (($directReleaseKnown && $supportReleaseKnown && $count->releasedCount !== $other->releasedCount)
                    || (($count->evidence['released_limits'] ?? false) && $other->count > 0)) {
                    return false;
                }

                continue;
            }
            if ($others->count() !== 1 || $others->first()->count !== $count->count || $others->first()->releasedCount !== $count->releasedCount) {
                return false;
            }
            $overlap++;
        }

        return $overlap > 0 || ! collect($left->speciesCounts)->contains(fn (ParsedSpeciesCountData $count): bool => $count->count !== null);
    }

    /**
     * @param  list<ParsedTripReportData>  $reports
     * @return array<int, array{boat: ?int, type: ?int, landing: ?int}>
     */
    private function identities(array $reports): array
    {
        $boats = Boat::query()->get(['id', 'slug', 'landing_id']);
        $boatIds = $boats->pluck('id', 'slug');
        $landings = $boats->pluck('landing_id', 'id');
        $boatAliases = BoatAlias::query()->pluck('boat_id', 'normalized_alias');
        $tripTypes = TripType::query()->pluck('id', 'slug');
        $tripAliases = TripTypeAlias::query()->pluck('trip_type_id', 'normalized_alias');
        $identities = [];
        foreach ($reports as $report) {
            $boat = $report->canonicalBoatId ?? $boatIds->get(Str::slug($report->boatName ?? '')) ?? $boatAliases->get(BoatNameNormalizer::normalize($report->boatName ?? ''));
            $type = $report->canonicalTripTypeId ?? $tripTypes->get(Str::slug($report->tripTypeName ?? '')) ?? $tripAliases->get(Str::lower($report->tripTypeName ?? ''));
            $identities[spl_object_id($report)] = ['boat' => $boat, 'type' => $type, 'landing' => $landings->get($boat)];
        }

        return $identities;
    }
}
