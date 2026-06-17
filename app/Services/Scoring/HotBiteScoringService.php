<?php

namespace App\Services\Scoring;

use App\Enums\ScoreLevel;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use Carbon\CarbonImmutable;

class HotBiteScoringService
{
    public function score(AlertRule $rule, CarbonImmutable $date, ScoreRun $scoreRun): ScoreResult
    {
        $score = 0;

        return ScoreResult::query()->updateOrCreate(
            ['alert_rule_id' => $rule->id, 'score_date' => $date->toDateString()],
            [
                'score_run_id' => $scoreRun->id,
                'score' => $score,
                'level' => ScoreLevel::fromScore($score),
                'total_count' => 0,
                'boat_count' => 0,
                'landing_count' => 0,
                'trend_score' => 0,
                'count_score' => 0,
                'count_per_angler_score' => 0,
                'breadth_score' => 0,
                'source_confidence_score' => 0,
                'explanation' => ['status' => 'No normalized reports available for this rule/date yet.'],
            ],
        );
    }
}
