<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackfillRun;
use App\Models\HistoricalAiReviewRun;
use App\Models\ParserError;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\User;
use App\Services\AI\AiReviewMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __invoke(AiReviewMetrics $aiReviewMetrics): View
    {
        $aiMetrics = $aiReviewMetrics->snapshot();

        return view('admin.dashboard', [
            'userCount' => User::query()->count(),
            'latestScrapeRun' => ScrapeRun::query()->latest()->first(),
            'latestBackfillRun' => BackfillRun::query()->latest()->first(),
            'openParserErrorCount' => ParserError::query()->whereNull('resolved_at')->count(),
            'failedJobCount' => DB::table('failed_jobs')->count(),
            'sources' => ScrapeSource::query()->orderBy('priority')->get(),
            'aiMetrics' => $aiMetrics,
            'aiOperationalAlerts' => $this->aiOperationalAlerts($aiMetrics),
            'historicalAiReviewRuns' => HistoricalAiReviewRun::query()->latest()->limit(5)->get(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<int, string>
     */
    private function aiOperationalAlerts(array $metrics): array
    {
        $alerts = [];

        if ($metrics['queue_depth'] >= (int) config('fish.ai_review.operations.queue_depth_warning')) {
            $alerts[] = 'AI review queue depth needs attention.';
        }

        if ($metrics['queue_oldest_age_seconds'] >= (int) config('fish.ai_review.operations.queue_age_warning_minutes') * 60) {
            $alerts[] = 'The oldest AI review job is waiting too long.';
        }

        if ($metrics['github_queue_depth'] >= (int) config('fish.ai_review.operations.queue_depth_warning')) {
            $alerts[] = 'GitHub issue queue depth needs attention.';
        }

        if ($metrics['github_queue_oldest_age_seconds'] >= (int) config('fish.ai_review.operations.queue_age_warning_minutes') * 60) {
            $alerts[] = 'The oldest GitHub issue job is waiting too long.';
        }

        if ($metrics['failed'] >= (int) config('fish.ai_review.operations.failure_warning')) {
            $alerts[] = 'AI review failures are above the 24-hour warning threshold.';
        }

        if ($metrics['stale'] >= (int) config('fish.ai_review.operations.stale_review_warning')) {
            $alerts[] = 'Stale AI reviews are above the warning threshold.';
        }

        if ($metrics['github_failures'] >= (int) config('fish.ai_review.operations.failure_warning')) {
            $alerts[] = 'GitHub issue failures are above the 24-hour warning threshold.';
        }

        foreach ($metrics['budgets'] as $budget) {
            if ($budget['remaining_micros'] === 0) {
                $alerts[] = "The {$budget['period']} AI review budget is exhausted.";
            }
        }

        return $alerts;
    }
}
