<?php

namespace App\Services\Parsing;

use App\Contracts\Parsing\RegulationResearcher;
use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\Enums\CatchQuantityBasis;
use App\Jobs\ResearchLimitsRegulationJob;
use App\Models\RawScrapePayload;
use App\Models\Species;

final class LimitsResolver
{
    public function __construct(private readonly LimitsClaimExtractor $claims, private readonly RegulationResearcher $researcher) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([config('limits.enabled'), config('limits.version'), config('limits.jurisdiction_version'), config('limits.duration_version'), config('limits.exceptions'), $this->researcher->fingerprint()], JSON_THROW_ON_ERROR));
    }

    public function enrich(ParsedFishCountCollection $parsed, RawScrapePayload $payload, bool $research = true): ParsedFishCountCollection
    {
        if (! config('limits.enabled')) {
            return $parsed;
        }

        return new ParsedFishCountCollection($parsed->tripReports->map(fn (ParsedTripReportData $report): ParsedTripReportData => $this->resolve($report, $payload, $research)), $parsed->parserVersion, $parsed->format);
    }

    public function resolve(ParsedTripReportData $report, RawScrapePayload $payload, bool $research = true): ParsedTripReportData
    {
        $counts = $report->speciesCounts;
        foreach ($this->claims->extract($report->rawFishCountText ?? '') as $claim) {
            if (! collect($counts)->contains(fn (ParsedSpeciesCountData $count): bool => $this->claims->identity($count->speciesName) === $this->claims->identity($claim->speciesName))) {
                $counts[] = $claim;
            }
        }
        $context = $this->context($report);
        $rules = [];
        foreach ($counts as $index => $count) {
            if (! isset($report->metadata['limits_source_conflict']) && $count->count === null && $context !== null && ($count->evidence['full_limits'] ?? false) && $report->anglers > 0) {
                $rules[$index] = $this->researcher->find($context['jurisdiction'], $this->claims->identity($count->speciesName), $report->tripDate->toDateString());
                if ($rules[$index] === null && $research && config('limits.research_enabled')) {
                    ResearchLimitsRegulationJob::dispatch($context['jurisdiction'], $this->claims->identity($count->speciesName), $report->tripDate->toDateString())->onConnection('database');
                }
            }
        }
        $resolved = [];
        foreach ($counts as $index => $count) {
            $id = $count->canonicalSpeciesId;
            if ($id === null && $this->claims->identity($count->speciesName) === 'vermilion_sunset') {
                $id = Species::query()->where('is_active', true)->whereIn('slug', ['vermilion-rockfish', 'red-rock-fish', 'rockfish'])->orderByRaw("CASE WHEN slug = 'rockfish' THEN 1 ELSE 0 END")->value('id');
            }
            $evidence = [...$count->evidence, 'payload_id' => $payload->id, 'payload_hash' => $payload->payload_hash, 'resolver_version' => config('limits.version'), 'regulatory_taxon' => $this->claims->identity($count->speciesName)];
            $quantity = $count->count;
            $basis = $count->quantityBasis;
            if ($quantity === null) {
                $rule = $rules[$index] ?? null;
                $estimate = $rule === null ? null : $this->calculate($report, $counts, $rules, $index, $context);
                if ($estimate !== null) {
                    $quantity = $estimate;
                    $basis = CatchQuantityBasis::LimitsEstimated;
                    $days = min($context['days'], $rule['max_days']);
                    $participants = $count->evidence['subset_anglers'] ?? $report->anglers;
                    $evidence = [...$evidence, ...$context, 'participating_anglers' => $participants, 'estimated_retained' => $quantity, 'raw_trip_type' => $report->tripTypeName, 'source_block' => $report->rawFishCountText, 'anglers_evidence' => $report->metadata['corroborated_anglers'] ?? ['payload_id' => $payload->id, 'source_block' => $report->rawFishCountText], 'anglers' => $report->anglers, 'rule' => $rule, 'allowed_days' => $days, 'regulatory_taxon' => $this->claims->identity($count->speciesName), 'formula' => "{$participants} anglers × {$rule['daily']} per person × {$days} days; shared quotas applied", 'jurisdiction_policy' => config('limits.jurisdiction_version'), 'duration_policy' => config('limits.duration_version')];
                } else {
                    $evidence['unresolved_reason'] = $context === null || ! $report->anglers ? 'missing_required_inputs' : ($rule === null ? 'no_verified_trip_date_rule' : 'non_unique_or_restricted_allocation');
                }
            }
            $isLimits = $count->isLimits || $this->claims->hasExplicitLimits($report->rawFishCountText ?? '', $count->speciesName);
            $resolved[] = new ParsedSpeciesCountData($count->speciesName, $quantity, $count->releasedCount, $count->rawText, $id, $basis, $isLimits, $evidence);
        }

        return new ParsedTripReportData($report->sourceKey, $report->tripDate, $report->regionName, $report->landingName, $report->boatName, $report->tripTypeName, $report->anglers, $report->rawFishCountText, $resolved, [...$report->metadata, 'limits_resolver' => config('limits.version'), 'limits_taxa' => array_values(array_unique(array_map(fn (ParsedSpeciesCountData $count): string => $this->claims->identity($count->speciesName), array_filter($resolved, fn (ParsedSpeciesCountData $count): bool => $count->isLimits)))), 'limits_jurisdiction' => $context['jurisdiction'] ?? null], $report->canonicalBoatId, $report->canonicalTripTypeId);
    }

    /** @return array{jurisdiction: string, days: int, assumptions: list<string>}|null */
    public function context(ParsedTripReportData $report): ?array
    {
        if (blank($report->tripTypeName)) {
            return null;
        }
        $label = $report->tripTypeName;
        $text = implode(' ', [$report->rawFishCountText ?? '', ...array_filter($report->metadata['ai_evidence_spans'] ?? [], is_string(...))]);
        $assumptions = ['Reported anglers are assumed eligible passengers; crew catch is excluded'];
        $duration = null;
        if (preg_match('/\b(?<duration>1\/2|3\/4|\d+(?:\.\d+)?)\s*days?\b/i', $label.' '.$text, $match)) {
            $duration = match ($match['duration']) {
                '1/2' => 0.5, '3/4' => 0.75, default => (float) $match['duration']
            };
        } elseif (preg_match('/half\s*day|twilight/i', $label)) {
            $duration = 0.5;
        } elseif (preg_match('/full\s*day|overnight|long\s*range|multi[- ]day/i', $label)) {
            $duration = 1;
            if (preg_match('/long\s*range|multi[- ]day/i', $label)) {
                $assumptions[] = 'Named trip without numeric duration uses one daily bag';
            }
        }
        if ($duration === null || $duration <= 0 || $duration > 365) {
            return null;
        }
        $days = max(1, (int) floor($duration));
        $mexico = preg_match('/\b(?:Mexic(?:o|an)|Baja|Coronado\s+Islands)\b/i', $label) === 1
            || preg_match('/\b(?:Mexican\s+waters|(?:in|off|near)\s+(?:the\s+)?(?:Mexico|Baja)|waters\s+(?:of|off)\s+Mexico|Coronado\s+Islands)\b/i', $text) === 1;
        $california = preg_match('/\b(?:California|United\s+States|U\.?S\.?(?:A\.?)?|Catalina|San\s+Clemente\s+Island)(?=\s|$|[(),;-])/i', $label) === 1
            || preg_match('/\b(?:(?:California|United\s+States|U\.?S\.?(?:A\.?)?)\s+waters|Catalina|San\s+Clemente\s+Island)\b/i', $text) === 1;
        if ($mexico && $california) {
            return null;
        }
        if ($mexico || $california) {
            $jurisdiction = $mexico ? 'mexico' : 'california_southern';
            $assumptions[] = 'Jurisdiction selected from explicit catch-location or destination wording';
        } elseif (preg_match('/\blocal\b/i', $label.' '.$text)) {
            $jurisdiction = 'california_southern';
            $assumptions[] = 'Local trip wording is assumed Southern California';
        } elseif (isset(config('limits.exceptions', [])[$report->sourceKey][$label])) {
            $jurisdiction = config('limits.exceptions')[$report->sourceKey][$label];
            $assumptions[] = 'Jurisdiction from configured source/trip exception';
        } else {
            $jurisdiction = $duration >= 0.75 ? 'mexico' : 'california_southern';
            $assumptions[] = 'Jurisdiction assumed from trip duration';
        }
        $assumptions[] = 'Fractional trip days round down, with at least one daily bag';
        if ($jurisdiction === 'california_southern' && $days > 1) {
            $assumptions[] = 'Multi-day declaration eligibility is assumed, not verified';
        }

        return compact('jurisdiction', 'days', 'assumptions');
    }

    /** @param list<ParsedSpeciesCountData> $counts @param array<int, array<string, mixed>|null> $rules @param array<string, mixed> $context */
    public function calculate(ParsedTripReportData $report, array $counts, array $rules, int $index, array $context): ?int
    {
        $rule = $rules[$index];
        if (($rule['prohibited'] ?? false) || ! ($rule['applicability_verified'] ?? false) || ! is_int($rule['daily'] ?? null) || $rule['daily'] <= 0 || $report->anglers <= 0) {
            return null;
        }
        $days = min($context['days'], $rule['max_days']);
        $participants = $counts[$index]->evidence['subset_anglers'] ?? $report->anglers;
        if ($participants <= 0 || $participants > $report->anglers) {
            return null;
        }
        $quantity = $participants * $rule['daily'] * $days;
        foreach ($rule['aggregates'] ?? [] as $group) {
            $used = 0;
            foreach ($counts as $otherIndex => $other) {
                if ($otherIndex === $index || $other->releasedCount > 0 && $other->count === 0) {
                    continue;
                }
                $taxon = $this->claims->identity($other->speciesName);
                $weight = $group['weights'][$taxon] ?? (($group['all_finfish'] ?? false) && ! in_array($taxon, ['spiny_lobster', 'lobster', 'rock_crab', 'crab', 'squid', 'giant_squid', 'jumbo_squid', 'scallops', 'abalone'], true) ? 1 : 0);
                if ($weight === 0) {
                    continue;
                }
                if ($other->count === null) {
                    return null;
                }
                $used += $other->count * $weight;
            }
            $weight = $group['weights'][$this->claims->identity($counts[$index]->speciesName)] ?? 1;
            $remaining = $participants * $group['daily'] * $days - $used;
            $quantity = min($quantity, (int) floor($remaining / $weight));
        }

        return $quantity > 0 && $quantity <= (int) config('fish.ai_parsing.limits.max_count') ? $quantity : null;
    }
}
