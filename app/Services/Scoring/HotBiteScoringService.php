<?php

namespace App\Services\Scoring;

use App\Enums\ScoreLevel;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Models\TripReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class HotBiteScoringService
{
    public function score(AlertRule $rule, CarbonImmutable $date, ScoreRun $scoreRun): ScoreResult
    {
        $rule->loadMissing(['species', 'regions:id', 'landings:id', 'boats:id', 'tripTypes:id']);

        $metrics = $this->metricsFor($rule, $date);
        $baselineMetrics = $this->baselineMetricsFor($rule, $date);
        $config = config('fish.scoring.targets.'.$rule->species->slug, config('fish.scoring.targets.default'));

        $countScore = $this->boundedScore($metrics['total_count'], (float) $config['count_full_score']);
        $countPerAnglerScore = $this->boundedScore((float) $metrics['count_per_angler'], (float) $config['count_per_angler_full_score']);
        $boatBreadthScore = $this->boundedScore($metrics['boat_count'], (float) $config['boat_breadth_full_score']);
        $landingBreadthScore = $this->boundedScore($metrics['landing_count'], (float) $config['landing_breadth_full_score']);
        $breadthScore = (int) round(($boatBreadthScore * 0.65) + ($landingBreadthScore * 0.35));
        $trendScore = $this->trendScore($metrics['total_count'], $baselineMetrics['average_total_count']);
        $sourceConfidenceScore = (int) min(100, round($metrics['average_source_confidence']));

        $score = (int) round(
            ($countScore * 0.30)
            + ($countPerAnglerScore * 0.25)
            + ($trendScore * 0.20)
            + ($breadthScore * 0.15)
            + ($sourceConfidenceScore * 0.10)
        );

        $score = $this->applyRuleMinimums($rule, $metrics, max(0, min(100, $score)));

        return ScoreResult::query()->updateOrCreate(
            ['alert_rule_id' => $rule->id, 'score_date' => $date->toDateString()],
            [
                'score_run_id' => $scoreRun->id,
                'score' => $score,
                'level' => ScoreLevel::fromScore($score),
                'total_count' => $metrics['total_count'],
                'total_anglers' => $metrics['total_anglers'] > 0 ? $metrics['total_anglers'] : null,
                'count_per_angler' => $metrics['count_per_angler'] > 0 ? $metrics['count_per_angler'] : null,
                'boat_count' => $metrics['boat_count'],
                'landing_count' => $metrics['landing_count'],
                'trend_score' => $trendScore,
                'count_score' => $countScore,
                'count_per_angler_score' => $countPerAnglerScore,
                'breadth_score' => $breadthScore,
                'source_confidence_score' => $sourceConfidenceScore,
                'explanation' => [
                    'weights' => [
                        'total_count' => 30,
                        'count_per_angler' => 25,
                        'trend' => 20,
                        'breadth' => 15,
                        'source_confidence' => 10,
                    ],
                    'baseline_average_total_count' => $baselineMetrics['average_total_count'],
                    'rule_minimums_met' => $score > 0,
                ],
            ],
        );
    }

    /** @return array{total_count: int, total_anglers: int, count_per_angler: float, boat_count: int, landing_count: int, average_source_confidence: float} */
    private function metricsFor(AlertRule $rule, CarbonImmutable $date): array
    {
        $reports = $this->filteredReports($rule)
            ->whereDate('trip_date', $date->toDateString())
            ->leftJoin('species_counts', 'species_counts.trip_report_id', '=', 'trip_reports.id')
            ->where('species_counts.species_id', $rule->species_id)
            ->selectRaw('COALESCE(SUM(species_counts.count), 0) as total_count')
            ->selectRaw('COALESCE(SUM(COALESCE(trip_reports.anglers, 0)), 0) as total_anglers')
            ->selectRaw('COUNT(DISTINCT trip_reports.boat_id) as boat_count')
            ->selectRaw('COUNT(DISTINCT trip_reports.landing_id) as landing_count')
            ->selectRaw('COALESCE(AVG(trip_reports.source_confidence), 0) as average_source_confidence')
            ->first();

        $totalCount = (int) ($reports->total_count ?? 0);
        $totalAnglers = (int) ($reports->total_anglers ?? 0);

        return [
            'total_count' => $totalCount,
            'total_anglers' => $totalAnglers,
            'count_per_angler' => $totalAnglers > 0 ? round($totalCount / $totalAnglers, 2) : 0.0,
            'boat_count' => (int) ($reports->boat_count ?? 0),
            'landing_count' => (int) ($reports->landing_count ?? 0),
            'average_source_confidence' => (float) ($reports->average_source_confidence ?? 0),
        ];
    }

    /** @return array{average_total_count: float} */
    private function baselineMetricsFor(AlertRule $rule, CarbonImmutable $date): array
    {
        $start = $date->subDays($rule->baseline_window_days);
        $end = $date->subDay();

        $dailyCounts = $this->filteredReports($rule)
            ->whereBetween('trip_date', [$start->toDateString(), $end->toDateString()])
            ->join('species_counts', 'species_counts.trip_report_id', '=', 'trip_reports.id')
            ->where('species_counts.species_id', $rule->species_id)
            ->groupBy('trip_reports.trip_date')
            ->selectRaw('trip_reports.trip_date, SUM(species_counts.count) as total_count')
            ->get()
            ->pluck('total_count');

        return ['average_total_count' => $dailyCounts->isNotEmpty() ? round((float) $dailyCounts->avg(), 2) : 0.0];
    }

    /** @return Builder<TripReport> */
    private function filteredReports(AlertRule $rule): Builder
    {
        $query = TripReport::query()->where('is_deduped_primary', true);

        $regionIds = $rule->regions->pluck('id');
        $landingIds = $rule->landings->pluck('id');
        $boatIds = $rule->boats->pluck('id');
        $tripTypeIds = $rule->tripTypes->pluck('id');

        if ($regionIds->isNotEmpty()) {
            $query->whereIn('region_id', $regionIds);
        }

        if ($landingIds->isNotEmpty()) {
            $query->whereIn('landing_id', $landingIds);
        }

        if ($boatIds->isNotEmpty()) {
            $query->whereIn('boat_id', $boatIds);
        }

        if ($tripTypeIds->isNotEmpty()) {
            $query->whereIn('trip_type_id', $tripTypeIds);
        }

        return $query;
    }

    private function boundedScore(float|int $value, float $fullScoreAt): int
    {
        if ($fullScoreAt <= 0 || $value <= 0) {
            return 0;
        }

        return (int) min(100, round(($value / $fullScoreAt) * 100));
    }

    private function trendScore(int $totalCount, float $baselineAverage): int
    {
        if ($totalCount <= 0) {
            return 0;
        }

        if ($baselineAverage <= 0) {
            return 80;
        }

        return (int) max(0, min(100, round((($totalCount - $baselineAverage) / $baselineAverage) * 50 + 50)));
    }

    /** @param array{total_count: int, count_per_angler: float} $metrics */
    private function applyRuleMinimums(AlertRule $rule, array $metrics, int $score): int
    {
        if ($rule->minimum_total_count !== null && $metrics['total_count'] < $rule->minimum_total_count) {
            return min($score, $rule->minimum_score - 1);
        }

        if ($rule->minimum_count_per_angler !== null && $metrics['count_per_angler'] < (float) $rule->minimum_count_per_angler) {
            return min($score, $rule->minimum_score - 1);
        }

        return $score;
    }
}
