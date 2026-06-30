<?php

namespace App\Services\Notifications;

use App\Models\AlertRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WeeklyDigestBuilder
{
    public function __construct(private readonly TripDecisionBuilder $tripDecisionBuilder) {}

    /**
     * @return Collection<int, string>
     */
    public function lines(User $user, CarbonImmutable $weekEnding): Collection
    {
        return $this->summaries($user, $weekEnding)
            ->map(function (array $summary): string {
                if (! $summary['has_scores']) {
                    return "{$summary['rule_name']}: no scores this week.";
                }

                $tripOptions = $summary['trip_options']->isEmpty()
                    ? 'no ranked trip options'
                    : $summary['trip_options']->map(fn (array $trip): string => "{$trip['boat_name']} {$trip['trip_type']} {$trip['trip_date']} {$trip['target_count']} target")->implode(', ');

                $recommendedBoats = $summary['trip_recommendations']->isEmpty()
                    ? 'no booking links available'
                    : $summary['trip_recommendations']->map(fn (array $trip): string => "{$trip['boat_name']} {$trip['trip_type']} {$trip['trip_date']}")->implode(', ');

                return "{$summary['rule_name']}: {$summary['score']} {$summary['level']} on {$summary['score_date']} · {$summary['weekly_total']} target fish this week · best {$summary['best_day']} · trend {$summary['trend']} · {$summary['boat_count']} boats · ranked trips: {$tripOptions} · recommended: {$recommendedBoats}.";
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
                    'trip_options' => $summary['trip_options'],
                    'trip_recommendations' => $summary['trip_recommendations'],
                ];
            })
            ->values();
    }

    public function discordContent(User $user, CarbonImmutable $weekEnding): string
    {
        $lines = $this->lines($user, $weekEnding);
        $formattedWeekEnding = $weekEnding->format('n/j/Y');

        if ($lines->isEmpty()) {
            return "Weekly fishing digest for week ending {$formattedWeekEnding}: no digest-enabled alert rules.";
        }

        return "Weekly fishing digest for week ending {$formattedWeekEnding}:\n".$lines->implode("\n");
    }

    /**
     * @return array{weekly_total: int, best_day: string, trend: string, trip_options: Collection<int, array<string, mixed>>, trip_recommendations: Collection<int, array<string, mixed>>}
     */
    private function summaryForRule(AlertRule $rule, CarbonImmutable $weekEnding): array
    {
        $weekStart = $weekEnding->subDays(6)->toDateString();
        $weekEnd = $weekEnding->toDateString();
        $scores = $rule->scoreResults->sortBy('score_date')->values();
        $firstScore = $scores->first();
        $lastScore = $scores->last();
        $bestScore = $scores->sortByDesc('score')->first();
        $weeklyTotal = (int) $scores->sum('total_count');
        $tripOptions = $this->tripDecisionBuilder->rankedTrips($rule, CarbonImmutable::parse($weekStart), CarbonImmutable::parse($weekEnd));

        return [
            'weekly_total' => $weeklyTotal,
            'best_day' => $bestScore === null ? 'n/a' : "{$bestScore->score_date->format('n/j/Y')} ({$bestScore->score})",
            'trend' => $this->trendLabel($firstScore?->score, $lastScore?->score),
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
}
