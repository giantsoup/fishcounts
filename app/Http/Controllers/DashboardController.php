<?php

namespace App\Http\Controllers;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\ScrapeRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'latestScrapeRun' => ScrapeRun::query()->latest('created_at')->first(),
            'latestScoreResults' => ScoreResult::query()
                ->with(['alertRule.species'])
                ->whereHas('alertRule', fn ($query) => $query->where('user_id', $user->id))
                ->latest('score_date')
                ->limit(5)
                ->get(),
            'activeAlertRules' => AlertRule::query()
                ->with('species')
                ->whereBelongsTo($user)
                ->where('is_enabled', true)
                ->latest()
                ->limit(5)
                ->get(),
            'recentAlertEvents' => AlertEvent::query()
                ->whereBelongsTo($user)
                ->latest('event_date')
                ->limit(5)
                ->get(),
        ]);
    }
}
