<?php

namespace App\Services\Parsing;

use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Services\Scoring\HotBiteScoringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LimitsScoreRefresher
{
    public function __construct(private readonly TripReportNormalizer $normalizer, private readonly HotBiteScoringService $scoring) {}

    public function refresh(string $date): void
    {
        $changedDate = CarbonImmutable::parse($date);
        $this->normalizer->refreshPrimaryReports($date);
        $maxWindow = (int) AlertRule::query()->max(DB::raw('recent_window_days + comparison_window_days'));
        if ($maxWindow < 1) {
            return;
        }
        ScoreResult::query()->with(['alertRule.species', 'alertRule.regions:id', 'alertRule.landings:id', 'alertRule.boats:id', 'alertRule.tripTypes:id', 'scoreRun'])->where('score_date', '>=', $date)->where('score_date', '<', $changedDate->addDays($maxWindow)->toDateString())->orderBy('id')->get()
            ->each(function (ScoreResult $score) use ($changedDate): void {
                $window = $score->alertRule->recent_window_days + $score->alertRule->comparison_window_days;
                if ($score->score_date->toImmutable()->diffInDays($changedDate, absolute: true) < $window) {
                    $this->scoring->score($score->alertRule, $score->score_date->toImmutable(), $score->scoreRun);
                }
            });
    }
}
