<?php

namespace App\Services\Notifications;

use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\User;
use App\Services\Environmental\EnvironmentalConditionFormatter;
use App\Services\Environmental\EnvironmentalConditionProfileResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WeeklyDigestBuilder
{
    public function __construct(
        private readonly TripDecisionBuilder $tripDecisionBuilder,
        private readonly EnvironmentalConditionFormatter $environmentalConditionFormatter,
        private readonly EnvironmentalConditionProfileResolver $environmentalConditionProfileResolver,
    ) {}

    /**
     * @return Collection<int, string>
     */
    public function lines(User $user, CarbonImmutable $weekEnding, ?Collection $summaries = null): Collection
    {
        return ($summaries ?? $this->summaries($user, $weekEnding))
            ->map(function (array $summary): string {
                if (! $summary['has_scores']) {
                    return "{$summary['rule_name']}: no scores this week.";
                }

                $tripOptions = $summary['trip_options']->isEmpty()
                    ? 'no ranked trip options'
                    : $summary['trip_options']->map(fn (array $trip): string => "{$trip['boat_name']} {$trip['trip_type']} {$trip['trip_date']} {$trip['target_count']} target")->implode(', ');

                $recommendedBoats = $summary['trip_recommendations']->isEmpty()
                    ? 'no booking links available'
                    : $summary['trip_recommendations']->map(function (array $trip): string {
                        $departure = $trip['booking_departure_at_display'] ?? null;
                        $bookingTiming = filled($departure) ? "next departure {$departure}" : 'booking options';

                        return "{$trip['boat_name']} {$trip['trip_type']} catch {$trip['trip_date']}, {$bookingTiming} {$trip['booking_url']}";
                    })->implode(', ');

                $conditions = $this->environmentalConditionLine($summary['environmental_condition']);

                return "{$summary['rule_name']}: {$summary['score']} {$summary['level']} on {$summary['score_date']} · {$summary['weekly_total']} target fish this week · best {$summary['best_day']} · {$conditions} · trend {$summary['trend']} · {$summary['boat_count']} boats · ranked trips: {$tripOptions} · recommended: {$recommendedBoats}.";
            })
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     rule_name: string,
     *     species_name: string,
     *     has_scores: bool,
     *     score: int|null,
     *     level: string|null,
     *     level_label: string|null,
     *     score_date: string|null,
     *     weekly_total: int,
     *     best_day: string,
     *     trend: string,
     *     boat_count: int|null,
     *     environmental_condition: array<string, mixed>|null,
     *     trip_options: Collection<int, array<string, mixed>>,
     *     trip_recommendations: Collection<int, array<string, mixed>>
     * }>
     */
    public function summaries(User $user, CarbonImmutable $weekEnding): Collection
    {
        return $user->alertRules()
            ->with(['species', 'scoreResults' => fn ($query) => $query
                ->whereDate('score_date', '>=', $weekEnding->subDays(6)->toDateString())
                ->whereDate('score_date', '<=', $weekEnding->toDateString())
                ->latest('score_date')])
            ->where('include_in_weekly_digest', true)
            ->orderBy('name')
            ->get()
            ->map(function (AlertRule $rule) use ($weekEnding): array {
                $latest = $rule->scoreResults->first();

                if ($latest === null) {
                    return [
                        'rule_name' => $rule->name,
                        'species_name' => $rule->species->name,
                        'has_scores' => false,
                        'score' => null,
                        'level' => null,
                        'level_label' => null,
                        'score_date' => null,
                        'weekly_total' => 0,
                        'best_day' => 'n/a',
                        'trend' => 'n/a',
                        'boat_count' => null,
                        'environmental_condition' => null,
                        'trip_options' => collect(),
                        'trip_recommendations' => collect(),
                    ];
                }

                $summary = $this->summaryForRule($rule, $weekEnding);

                return [
                    'rule_name' => $rule->name,
                    'species_name' => $rule->species->name,
                    'has_scores' => true,
                    'score' => $latest->score,
                    'level' => $latest->level->value,
                    'level_label' => str($latest->level->value)->replace('_', ' ')->headline()->toString(),
                    'score_date' => $latest->score_date->format('n/j/Y'),
                    'weekly_total' => $summary['weekly_total'],
                    'best_day' => $summary['best_day'],
                    'trend' => $summary['trend'],
                    'boat_count' => $latest->boat_count,
                    'environmental_condition' => $summary['environmental_condition'],
                    'trip_options' => $summary['trip_options'],
                    'trip_recommendations' => $summary['trip_recommendations'],
                ];
            })
            ->values();
    }

    public function discordContent(User $user, CarbonImmutable $weekEnding, ?Collection $summaries = null): string
    {
        $lines = $this->lines($user, $weekEnding, $summaries);
        $formattedWeekEnding = $weekEnding->format('n/j/Y');

        if ($lines->isEmpty()) {
            return "Weekly fishing digest for week ending {$formattedWeekEnding}: no digest-enabled alert rules.";
        }

        return "Weekly fishing digest for week ending {$formattedWeekEnding}:"
            ."\n".$lines->implode("\n");
    }

    /**
     * @return array{weekly_total: int, best_day: string, trend: string, environmental_condition: array<string, mixed>|null, trip_options: Collection<int, array<string, mixed>>, trip_recommendations: Collection<int, array<string, mixed>>}
     */
    private function summaryForRule(AlertRule $rule, CarbonImmutable $weekEnding): array
    {
        $weekStart = $weekEnding->subDays(6)->toDateString();
        $weekEnd = $weekEnding->toDateString();
        $scores = $rule->scoreResults->sortBy('score_date')->values();
        $firstScore = $scores->first();
        $lastScore = $scores->last();
        $bestScore = $this->bestRelevantScore($scores, (int) $rule->minimum_score);
        $weeklyTotal = (int) $scores->sum('total_count');
        $tripOptions = $this->tripDecisionBuilder->rankedTrips($rule, CarbonImmutable::parse($weekStart), CarbonImmutable::parse($weekEnd));
        $locationProfile = $this->environmentalConditionProfileResolver->resolve($rule);

        return [
            'weekly_total' => $weeklyTotal,
            'best_day' => $bestScore === null ? 'n/a' : "{$bestScore->score_date->format('n/j/Y')} ({$bestScore->score})",
            'trend' => $this->trendLabel($firstScore?->score, $lastScore?->score),
            'environmental_condition' => $bestScore === null
                ? null
                : $this->environmentalConditionFormatter->detailsForDate($bestScore->score_date->toImmutable(), $locationProfile),
            'trip_options' => $tripOptions,
            'trip_recommendations' => $this->tripDecisionBuilder->recommendedBoats($tripOptions),
        ];
    }

    private function trendLabel(?int $firstScore, ?int $lastScore): string
    {
        if ($firstScore === null || $lastScore === null) {
            return 'n/a';
        }

        $delta = $lastScore - $firstScore;

        return match (true) {
            $delta > 0 => "+{$delta}",
            $delta < 0 => (string) $delta,
            default => 'flat',
        };
    }

    /**
     * @param  Collection<int, ScoreResult>  $scores
     */
    private function bestRelevantScore(Collection $scores, int $minimumScore): ?ScoreResult
    {
        $thresholdScores = $scores->filter(
            fn (ScoreResult $scoreResult): bool => $scoreResult->score >= $minimumScore,
        );
        $candidates = $thresholdScores->isNotEmpty() ? $thresholdScores : $scores;

        return $candidates
            ->sort(function (ScoreResult $left, ScoreResult $right): int {
                $scoreComparison = $right->score <=> $left->score;

                return $scoreComparison !== 0
                    ? $scoreComparison
                    : $right->score_date->getTimestamp() <=> $left->score_date->getTimestamp();
            })
            ->first();
    }

    /** @param  array<string, mixed>|null  $conditions */
    private function environmentalConditionLine(?array $conditions): string
    {
        if ($conditions === null) {
            return 'conditions unavailable';
        }

        if (! $conditions['available']) {
            return "{$conditions['location_label']} conditions unavailable";
        }

        $readings = collect([
            $conditions['water_temperature'] === null ? null : 'water '.$conditions['water_temperature'],
            $conditions['swell'] === null ? null : 'swell '.$conditions['swell'],
            $conditions['moon'] === null ? null : 'moon '.$conditions['moon'],
        ])->filter()->implode('; ');

        return $readings === ''
            ? "{$conditions['location_label']} conditions collected"
            : "{$conditions['location_label']} conditions: {$readings}";
    }
}
