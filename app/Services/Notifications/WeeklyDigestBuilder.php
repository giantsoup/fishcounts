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
        return $user->alertRules()
            ->with(['species', 'scoreResults' => fn ($query) => $query
                ->whereDate('score_date', '>=', $weekEnding->subDays(6)->toDateString())
                ->whereDate('score_date', '<=', $weekEnding->toDateString())
                ->latest('score_date')])
            ->where('include_in_weekly_digest', true)
            ->orderBy('name')
            ->get()
            ->map(function (AlertRule $rule) use ($weekEnding): string {
                $latest = $rule->scoreResults->first();

                if ($latest === null) {
                    return "{$rule->name}: no scores this week.";
                }

                $summary = $this->summaryForRule($rule, $weekEnding);
                $countPerAngler = $latest->count_per_angler === null ? 'n/a' : $latest->count_per_angler;
                $topBoats = $summary['top_boats']->isEmpty() ? 'no boat detail' : $summary['top_boats']->implode(', ');
                $topLandings = $summary['top_landings']->isEmpty() ? 'no landing detail' : $summary['top_landings']->implode(', ');

                return "{$rule->name}: {$latest->score} {$latest->level->value} on {$latest->score_date->toDateString()} · {$summary['weekly_total']} fish this week · best {$summary['best_day']} · trend {$summary['trend']} · {$latest->boat_count} boats · {$countPerAngler} fish/angler · top boats: {$topBoats} · top landings: {$topLandings} · data: {$summary['data_quality']}.";
            })
            ->values();
    }

    public function discordContent(User $user, CarbonImmutable $weekEnding): string
    {
        $lines = $this->lines($user, $weekEnding);

        if ($lines->isEmpty()) {
            return "Weekly fishing digest for week ending {$weekEnding->toDateString()}: no digest-enabled alert rules.";
        }

        return "Weekly fishing digest for week ending {$weekEnding->toDateString()}:\n".$lines->implode("\n");
    }

    /**
     * @return array{weekly_total: int, best_day: string, trend: string, top_boats: Collection<int, string>, top_landings: Collection<int, string>, data_quality: string}
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
            ->map(fn ($row): string => "{$row->name} {$row->total}");

        $topLandings = (clone $reportsQuery)
            ->leftJoin('landings', 'landings.id', '=', 'trip_reports.landing_id')
            ->selectRaw("COALESCE(landings.name, trip_reports.raw_landing_name, 'Unknown landing') as name, SUM(species_counts.count) as total")
            ->groupByRaw("COALESCE(landings.name, trip_reports.raw_landing_name, 'Unknown landing')")
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(fn ($row): string => "{$row->name} {$row->total}");

        $missingAnglerReports = (clone $reportsQuery)
            ->whereNull('trip_reports.anglers')
            ->count('trip_reports.id');

        return [
            'weekly_total' => $weeklyTotal,
            'best_day' => $bestScore === null ? 'n/a' : "{$bestScore->score_date->toDateString()} ({$bestScore->score})",
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
