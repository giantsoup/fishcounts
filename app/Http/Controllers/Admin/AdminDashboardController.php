<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiBudgetPeriodType;
use App\Http\Controllers\Controller;
use App\Models\AiBudgetPeriod;
use App\Models\BackfillRun;
use App\Models\HistoricalAiReviewRun;
use App\Models\ParserError;
use App\Models\ParserExecution;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\User;
use App\Services\AI\AiReviewMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __invoke(AiReviewMetrics $aiReviewMetrics): View
    {
        $aiMetrics = $aiReviewMetrics->snapshot();
        $parserMetrics = $this->parserMetrics();

        return view('admin.dashboard', [
            'userCount' => User::query()->count(),
            'latestScrapeRun' => ScrapeRun::query()->latest()->first(),
            'latestBackfillRun' => BackfillRun::query()->latest()->first(),
            'openParserErrorCount' => ParserError::query()->whereNull('resolved_at')->count(),
            'failedJobCount' => DB::table('failed_jobs')->count(),
            'sources' => ScrapeSource::query()->orderBy('priority')->get(),
            'aiMetrics' => $aiMetrics,
            'aiOperationalAlerts' => $this->aiOperationalAlerts($aiMetrics, $parserMetrics),
            'historicalAiReviewRuns' => HistoricalAiReviewRun::query()->latest()->limit(5)->get(),
            'parserMetrics' => $parserMetrics,
        ]);
    }

    /** @return array<string, int|float> */
    private function parserMetrics(): array
    {
        $recent = ParserExecution::query()->where('created_at', '>=', now()->subDay());
        $budgetNow = CarbonImmutable::now((string) config('fish.ai_parsing.budgets.timezone'));
        $budgetPeriods = AiBudgetPeriod::query()
            ->where('provider', config('fish.ai_parsing.provider'))
            ->where(function ($query) use ($budgetNow): void {
                $query->where(function ($query) use ($budgetNow): void {
                    $query->where('period_type', AiBudgetPeriodType::Daily)
                        ->whereDate('period_start', $budgetNow->toDateString());
                })->orWhere(function ($query) use ($budgetNow): void {
                    $query->where('period_type', AiBudgetPeriodType::Monthly)
                        ->whereDate('period_start', $budgetNow->startOfMonth()->toDateString());
                });
            })->get()->keyBy(fn (AiBudgetPeriod $period): string => $period->period_type->value);
        $dailyBudget = $budgetPeriods->get(AiBudgetPeriodType::Daily->value);
        $monthlyBudget = $budgetPeriods->get(AiBudgetPeriodType::Monthly->value);
        $dailyCost = $dailyBudget?->spent_micros ?? 0;
        $monthlyCost = $monthlyBudget?->spent_micros ?? 0;
        $oldest = DB::table('jobs')->where('queue', 'ai-primary-parsing')->min('created_at');

        return [
            'queue_depth' => DB::table('jobs')->where('queue', 'ai-primary-parsing')->count(),
            'queue_oldest_age_seconds' => $oldest === null ? 0 : max(0, now()->timestamp - (int) $oldest),
            'ai_success' => (clone $recent)->where('selected_engine', 'ai')->where('status', 'completed')->count(),
            'fallbacks' => (clone $recent)->whereNotNull('fallback_category')->count(),
            'mismatches' => (clone $recent)->where('comparison_status', 'different')->count(),
            'failures' => (clone $recent)->where('status', 'failed')->count(),
            'validation_failures' => (clone $recent)
                ->whereIn('fallback_category', ['domain_validation', 'ai_validation_failure'])
                ->count(),
            'estimated_costs' => (clone $recent)->where('cost_is_estimated', true)->count(),
            'average_latency_ms' => (float) ((clone $recent)->whereNotNull('latency_ms')->avg('latency_ms') ?? 0),
            'tokens' => (int) (clone $recent)->sum('total_tokens'),
            'daily_cost_micros' => (int) $dailyCost,
            'monthly_cost_micros' => (int) $monthlyCost,
            'daily_remaining_micros' => max(0, (int) config('fish.ai_parsing.budgets.daily_limit_micros') - (int) $dailyCost - (int) ($dailyBudget?->reserved_micros ?? 0)),
            'monthly_remaining_micros' => max(0, (int) config('fish.ai_parsing.budgets.monthly_limit_micros') - (int) $monthlyCost - (int) ($monthlyBudget?->reserved_micros ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $parserMetrics
     * @return array<int, string>
     */
    private function aiOperationalAlerts(array $metrics, array $parserMetrics): array
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

        if ($parserMetrics['queue_oldest_age_seconds'] >= (int) config('fish.ai_parsing.operations.queue_age_warning_minutes') * 60) {
            $alerts[] = 'The oldest AI primary parsing job is waiting too long.';
        }
        if ($parserMetrics['failures'] >= (int) config('fish.ai_parsing.operations.failure_warning')) {
            $alerts[] = 'AI primary parsing failures are above the 24-hour warning threshold.';
        }
        if ($parserMetrics['estimated_costs'] > 0) {
            $alerts[] = 'Some AI primary parsing costs are conservative estimates because provider usage could not be validated.';
        }
        if ($parserMetrics['daily_remaining_micros'] === 0 || $parserMetrics['monthly_remaining_micros'] === 0) {
            $alerts[] = 'An AI primary parsing budget is exhausted; deterministic fallback is active.';
        }

        return $alerts;
    }
}
