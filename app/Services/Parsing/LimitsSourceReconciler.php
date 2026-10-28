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
        $matches = [];
        foreach ($parsed->tripReports as $index => $report) {
            $matches[$index] = array_keys(array_filter($candidates, fn (array $candidate): bool => $this->matches($report, $candidate['report'])));
        }
        $usages = array_count_values(array_merge([], ...array_values($matches)));
        $reports = $parsed->tripReports->map(function (ParsedTripReportData $report, int $index) use ($candidates, $matches, $usages): ParsedTripReportData {
            if (preg_match('/\blimits\b/i', $report->rawFishCountText ?? '') !== 1) {
                return $report;
            }
            if (count($matches[$index]) !== 1 || $usages[$matches[$index][0]] !== 1) {
                $conflicts = array_filter($candidates, fn (array $candidate): bool => $this->boat($report) !== null && $this->boat($report) === $this->boat($candidate['report']) && $this->tripType($report) !== null && $this->tripType($report) === $this->tripType($candidate['report']));
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
                    && $this->claims->identity($otherCount->speciesName) === $this->claims->identity($count->speciesName)
                    && ($otherCount->count !== 0 || $this->claims->hasExplicitRetainedZero($otherCount->rawText ?? '', $otherCount->speciesName)));
                if ($explicit->count() !== 1) {
                    return $count;
                }
                $evidence = $explicit->first();

                return new ParsedSpeciesCountData($count->speciesName, $evidence->count, $count->releasedCount, $count->rawText, $count->canonicalSpeciesId ?? $evidence->canonicalSpeciesId, CatchQuantityBasis::Corroborated, true,
                    [...$count->evidence, 'supporting_payload_id' => $candidate['payload']->id, 'supporting_payload_hash' => $candidate['payload']->payload_hash, 'explicit_span' => $evidence->rawText, 'supporting_species' => $evidence->speciesName]);
            }, $counts);
            $metadata = $report->metadata;
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
        return RawScrapePayload::query()->whereDate('target_date', $payload->target_date)->where('scrape_source_id', '!=', $payload->scrape_source_id)->orderByDesc('fetched_at')->orderByDesc('id')->get()->unique('scrape_source_id')->values();
    }

    private function matches(ParsedTripReportData $left, ParsedTripReportData $right): bool
    {
        $boat = $this->boat($left);
        $type = $this->tripType($left);
        if ($boat === null || $type === null || $boat !== $this->boat($right) || $type !== $this->tripType($right)
            || ! $left->tripDate->isSameDay($right->tripDate)
            || ($left->anglers !== null && $right->anglers !== null && $left->anglers !== $right->anglers)) {
            return false;
        }
        $landing = Boat::query()->find($boat)?->landing_id;
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
            if ($count->count === null) {
                continue;
            }
            $others = collect($right->speciesCounts)->filter(fn (ParsedSpeciesCountData $other): bool => $other->count !== null && $this->claims->identity($count->speciesName) === $this->claims->identity($other->speciesName));
            if ($others->isEmpty()) {
                continue;
            }
            if ($others->count() !== 1 || $others->first()->count !== $count->count || $others->first()->releasedCount !== $count->releasedCount) {
                return false;
            }
            $overlap++;
        }

        return $overlap > 0 || ! collect($left->speciesCounts)->contains(fn (ParsedSpeciesCountData $count): bool => $count->count !== null);
    }

    private function boat(ParsedTripReportData $report): ?int
    {
        return $report->canonicalBoatId ?? Boat::query()->where('slug', Str::slug($report->boatName ?? ''))->value('id')
            ?? BoatAlias::query()->where('normalized_alias', BoatNameNormalizer::normalize($report->boatName ?? ''))->value('boat_id');
    }

    private function tripType(ParsedTripReportData $report): ?int
    {
        return $report->canonicalTripTypeId ?? TripType::query()->where('slug', Str::slug($report->tripTypeName ?? ''))->value('id')
            ?? TripTypeAlias::query()->where('normalized_alias', Str::lower($report->tripTypeName ?? ''))->value('trip_type_id');
    }
}
