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
        $trendMetrics = $this->trendMetricsFor($rule, $date);
        $config = config('fish.scoring.targets.'.$rule->species->slug, config('fish.scoring.targets.default'));

        $countScore = $this->boundedScore($metrics['total_count'], (float) $config['count_full_score']);
        $countPerAnglerScore = $this->boundedScore((float) $metrics['count_per_angler'], (float) $config['count_per_angler_full_score']);
        $boatBreadthScore = $this->boundedScore($metrics['boat_count'], (float) $config['boat_breadth_full_score']);
        $landingBreadthScore = $this->boundedScore($metrics['landing_count'], (float) $config['landing_breadth_full_score']);
        $breadthScore = (int) round(($boatBreadthScore * 0.65) + ($landingBreadthScore * 0.35));
        $trendScore = $this->trendScore($trendMetrics['recent_average_total_count'], $trendMetrics['comparison_average_total_count']);
        $sourceConfidenceScore = (int) min(100, round($metrics['average_source_confidence']));

        $score = (int) round(
            ($countScore * 0.30)
            + ($countPerAnglerScore * 0.25)
            + ($trendScore * 0.20)
            + ($breadthScore * 0.15)
            + ($sourceConfidenceScore * 0.10)
        );

        $score = $this->applyRuleMinimums($rule, $metrics, max(0, min(100, $score)));

        return ScoreResult::query()->whereDate('score_date', $date->toDateString())->updateOrCreate(
            ['alert_rule_id' => $rule->id],
            [
                'score_date' => $date->toDateString(),
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
                    'includes_estimates' => $metrics['includes_estimates'] || $trendMetrics['includes_estimates'],
                    'incomplete_quantity_coverage' => $metrics['incomplete_quantity_coverage'] || $trendMetrics['incomplete_quantity_coverage'],
                    'weights' => [
                        'total_count' => 30,
                        'count_per_angler' => 25,
                        'trend' => 20,
                        'breadth' => 15,
                        'source_confidence' => 10,
                    ],
                    'recent_window_days' => $trendMetrics['recent_window_days'],
                    'comparison_window_days' => $trendMetrics['comparison_window_days'],
                    'recent_window_start' => $trendMetrics['recent_window_start'],
                    'recent_window_end' => $trendMetrics['recent_window_end'],
                    'comparison_window_start' => $trendMetrics['comparison_window_start'],
                    'comparison_window_end' => $trendMetrics['comparison_window_end'],
                    'recent_average_total_count' => $trendMetrics['recent_average_total_count'],
                    'comparison_average_total_count' => $trendMetrics['comparison_average_total_count'],
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
            ->selectRaw('COALESCE(SUM(CASE WHEN species_counts.count IS NOT NULL THEN COALESCE(trip_reports.anglers, 0) ELSE 0 END), 0) as total_anglers')
            ->selectRaw('COALESCE(MAX(species_counts.has_estimated_components), 0) as includes_estimates')
            ->selectRaw('COALESCE(MAX(species_counts.has_unquantified_components), 0) as incomplete_quantity_coverage')
            ->selectRaw('COUNT(DISTINCT CASE WHEN species_counts.count IS NOT NULL THEN trip_reports.boat_id END) as boat_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN species_counts.count IS NOT NULL THEN trip_reports.landing_id END) as landing_count')
            ->selectRaw('COALESCE(AVG(CASE WHEN species_counts.count IS NOT NULL THEN trip_reports.source_confidence END), 0) as average_source_confidence')
            ->first();

        $totalCount = (int) ($reports->total_count ?? 0);
        $totalAnglers = (int) ($reports->total_anglers ?? 0);

        return [
            'includes_estimates' => (bool) ($reports->includes_estimates ?? false),
            'incomplete_quantity_coverage' => (bool) ($reports->incomplete_quantity_coverage ?? false),
            'total_count' => $totalCount,
            'total_anglers' => $totalAnglers,
            'count_per_angler' => $totalAnglers > 0 ? round($totalCount / $totalAnglers, 2) : 0.0,
            'boat_count' => (int) ($reports->boat_count ?? 0),
            'landing_count' => (int) ($reports->landing_count ?? 0),
            'average_source_confidence' => (float) ($reports->average_source_confidence ?? 0),
        ];
    }

    /**
     * @return array{
     *     recent_window_days: int,
     *     comparison_window_days: int,
     *     recent_window_start: string,
     *     recent_window_end: string,
     *     comparison_window_start: string,
     *     comparison_window_end: string,
     *     recent_average_total_count: float,
     *     comparison_average_total_count: float
     * }
     */
    private function trendMetricsFor(AlertRule $rule, CarbonImmutable $date): array
    {
        $recentWindowDays = max(1, (int) $rule->recent_window_days);
        $comparisonWindowDays = max(1, (int) $rule->comparison_window_days);
        $recentWindowEnd = $date;
        $recentWindowStart = $date->subDays($recentWindowDays - 1);
        $comparisonWindowEnd = $recentWindowStart->subDay();
        $comparisonWindowStart = $comparisonWindowEnd->subDays($comparisonWindowDays - 1);

        $recent = $this->windowMetrics($rule, $recentWindowStart, $recentWindowEnd, $recentWindowDays);
        $comparison = $this->windowMetrics($rule, $comparisonWindowStart, $comparisonWindowEnd, $comparisonWindowDays);

        return [
            'includes_estimates' => $recent['includes_estimates'] || $comparison['includes_estimates'],
            'incomplete_quantity_coverage' => $recent['incomplete_quantity_coverage'] || $comparison['incomplete_quantity_coverage'],
            'recent_window_days' => $recentWindowDays,
            'comparison_window_days' => $comparisonWindowDays,
            'recent_window_start' => $recentWindowStart->toDateString(),
            'recent_window_end' => $recentWindowEnd->toDateString(),
            'comparison_window_start' => $comparisonWindowStart->toDateString(),
            'comparison_window_end' => $comparisonWindowEnd->toDateString(),
            'recent_average_total_count' => $recent['average'],
            'comparison_average_total_count' => $comparison['average'],
        ];
    }

    /** @return array{average: ?float, includes_estimates: bool, incomplete_quantity_coverage: bool} */
    private function windowMetrics(AlertRule $rule, CarbonImmutable $start, CarbonImmutable $end, int $windowDays): array
    {
        $dailyCounts = $this->filteredReports($rule)
            ->where('trip_date', '>=', $start->toDateString())
            ->where('trip_date', '<', $end->addDay()->toDateString())
            ->join('species_counts', 'species_counts.trip_report_id', '=', 'trip_reports.id')
            ->where('species_counts.species_id', $rule->species_id)
            ->groupBy('trip_reports.trip_date')
            ->selectRaw('trip_reports.trip_date, SUM(species_counts.count) as total_count, MAX(species_counts.has_estimated_components) as estimated, MAX(species_counts.has_unquantified_components) as incomplete')
            ->get();
        $knownDays = max(0, $windowDays - $dailyCounts->whereNull('total_count')->count());

        return ['average' => $knownDays > 0 ? round((float) $dailyCounts->sum('total_count') / $knownDays, 2) : null,
            'includes_estimates' => $dailyCounts->contains('estimated', 1),
            'incomplete_quantity_coverage' => $dailyCounts->contains('incomplete', 1)];
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

    private function trendScore(?float $recentAverage, ?float $comparisonAverage): int
    {
        if ($recentAverage === null || $comparisonAverage === null || $recentAverage <= 0) {
            return 0;
        }

        if ($comparisonAverage <= 0) {
            return 80;
        }

        return (int) max(0, min(100, round((($recentAverage - $comparisonAverage) / $comparisonAverage) * 50 + 50)));
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
