<?php

namespace App\Services\Notifications;

use App\Models\AlertRule;
use App\Models\TripReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WeeklyDigestBuilder
{
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

                $topBoats = $summary['top_boats']->isEmpty()
                    ? 'no boat detail'
                    : $summary['top_boats']->map(fn (array $boat): string => "{$boat['name']} {$boat['total']}")->implode(', ');
                $topLandings = $summary['top_landings']->isEmpty()
                    ? 'no landing detail'
                    : $summary['top_landings']->map(fn (array $landing): string => "{$landing['name']} {$landing['total']}")->implode(', ');

                return "{$summary['rule_name']}: {$summary['score']} {$summary['level']} on {$summary['score_date']} · {$summary['weekly_total']} fish this week · best {$summary['best_day']} · trend {$summary['trend']} · {$summary['boat_count']} boats · {$summary['count_per_angler']} fish/angler · top boats: {$topBoats} · top landings: {$topLandings} · data: {$summary['data_quality']}.";
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
     *     count_per_angler: string,
     *     top_boats: Collection<int, array{name: string, total: int}>,
     *     top_landings: Collection<int, array{name: string, total: int}>,
     *     data_quality: string
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
                        'count_per_angler' => 'n/a',
                        'top_boats' => collect(),
                        'top_landings' => collect(),
                        'data_quality' => 'no score data available',
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
                    'count_per_angler' => $latest->count_per_angler === null ? 'n/a' : (string) $latest->count_per_angler,
                    'top_boats' => $summary['top_boats'],
                    'top_landings' => $summary['top_landings'],
                    'data_quality' => $summary['data_quality'],
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
     * @return array{weekly_total: int, best_day: string, trend: string, top_boats: Collection<int, array{name: string, total: int}>, top_landings: Collection<int, array{name: string, total: int}>, data_quality: string}
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
        $reportsQuery = $this->filteredReports($rule)
            ->whereDate('trip_date', '>=', $weekStart)
            ->whereDate('trip_date', '<=', $weekEnd)
            ->join('species_counts', 'species_counts.trip_report_id', '=', 'trip_reports.id')
            ->where('species_counts.species_id', $rule->species_id);

        $topBoats = (clone $reportsQuery)
            ->leftJoin('boats', 'boats.id', '=', 'trip_reports.boat_id')
            ->selectRaw("COALESCE(boats.name, trip_reports.raw_boat_name, 'Unknown boat') as name, SUM(species_counts.count) as total")
            ->groupByRaw("COALESCE(boats.name, trip_reports.raw_boat_name, 'Unknown boat')")
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name,
                'total' => (int) $row->total,
            ]);

        $topLandings = (clone $reportsQuery)
            ->leftJoin('landings', 'landings.id', '=', 'trip_reports.landing_id')
            ->selectRaw("COALESCE(landings.name, trip_reports.raw_landing_name, 'Unknown landing') as name, SUM(species_counts.count) as total")
            ->groupByRaw("COALESCE(landings.name, trip_reports.raw_landing_name, 'Unknown landing')")
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name,
                'total' => (int) $row->total,
            ]);

        $missingAnglerReports = (clone $reportsQuery)
            ->whereNull('trip_reports.anglers')
            ->count('trip_reports.id');

        return [
            'weekly_total' => $weeklyTotal,
            'best_day' => $bestScore === null ? 'n/a' : "{$bestScore->score_date->format('n/j/Y')} ({$bestScore->score})",
            'trend' => $this->trendLabel($firstScore?->score, $lastScore?->score),
            'top_boats' => $topBoats,
            'top_landings' => $topLandings,
            'data_quality' => $missingAnglerReports > 0 ? "{$missingAnglerReports} report(s) missing anglers" : 'complete angler data where available',
        ];
    }

    /** @return Builder<TripReport> */
    private function filteredReports(AlertRule $rule): Builder
    {
        $rule->loadMissing(['regions:id', 'landings:id', 'boats:id', 'tripTypes:id']);
        $query = TripReport::query()->where('is_deduped_primary', true);

        if ($rule->regions->isNotEmpty()) {
            $query->whereIn('trip_reports.region_id', $rule->regions->pluck('id'));
        }

        if ($rule->landings->isNotEmpty()) {
            $query->whereIn('trip_reports.landing_id', $rule->landings->pluck('id'));
        }

        if ($rule->boats->isNotEmpty()) {
            $query->whereIn('trip_reports.boat_id', $rule->boats->pluck('id'));
        }

        if ($rule->tripTypes->isNotEmpty()) {
            $query->whereIn('trip_reports.trip_type_id', $rule->tripTypes->pluck('id'));
        }

        return $query;
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
