<?php

namespace App\Http\Controllers;

use App\Enums\ScoreLevel;
use App\Http\Requests\ScoresIndexRequest;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use Illuminate\Contracts\View\View;

class ScoresController extends Controller
{
    public function __invoke(ScoresIndexRequest $request): View
    {
        $filters = $request->filters();

        return view('scores.index', [
            'scores' => ScoreResult::query()
                ->with(['alertRule.species'])
                ->whereHas('alertRule', fn ($query) => $query->where('user_id', $request->user()->id))
                ->whereBetween('score_date', [$filters['from'], $filters['to']])
                ->when($filters['alert_rule_id'], fn ($query, int $alertRuleId) => $query->where('alert_rule_id', $alertRuleId))
                ->when($filters['level'], fn ($query, string $level) => $query->where('level', $level))
                ->when($filters['minimum_score'] !== null, fn ($query) => $query->where('score', '>=', $filters['minimum_score']))
                ->latest('score_date')
                ->orderByDesc('score')
                ->paginate(50)
                ->withQueryString(),
            'filters' => $filters,
            'rules' => AlertRule::query()
                ->whereBelongsTo($request->user())
                ->orderBy('name')
                ->get(),
            'levels' => ScoreLevel::cases(),
        ]);
    }
}
