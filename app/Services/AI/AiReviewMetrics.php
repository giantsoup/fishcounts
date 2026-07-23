<?php

namespace App\Services\AI;

use App\Enums\AiBudgetPeriodType;
use App\Enums\ParserBugReportStatus;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewAction;
use App\Models\ParserReportOverride;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AiReviewMetrics
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $since = now()->subDay();
        $actionCounts = ParserDiagnosticReviewAction::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('action, COUNT(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');
        $oldestQueuedAt = DB::table('jobs')->where('queue', 'ai-parsing')->min('created_at');
        $oldestGitHubQueuedAt = DB::table('jobs')->where('queue', 'github-issues')->min('created_at');
        $usage = ParserDiagnosticReview::query()
            ->where(function ($query) use ($since): void {
                $query->where('completed_at', '>=', $since)
                    ->orWhere('failed_at', '>=', $since);
            })
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as tokens')
            ->first();

        return [
            'queue_depth' => DB::table('jobs')->where('queue', 'ai-parsing')->count(),
            'queue_oldest_age_seconds' => $oldestQueuedAt === null ? 0 : max(0, now()->timestamp - (int) $oldestQueuedAt),
            'github_queue_depth' => DB::table('jobs')->where('queue', 'github-issues')->count(),
            'github_queue_oldest_age_seconds' => $oldestGitHubQueuedAt === null ? 0 : max(0, now()->timestamp - (int) $oldestGitHubQueuedAt),
            'succeeded' => ParserDiagnosticReview::query()->where('status', ParserDiagnosticReviewStatus::Succeeded)->where('completed_at', '>=', $since)->count(),
            'failed' => ParserDiagnosticReview::query()->where('status', ParserDiagnosticReviewStatus::Failed)->where('failed_at', '>=', $since)->count(),
            'refused' => ParserDiagnosticReview::query()->where('status', ParserDiagnosticReviewStatus::Refused)->where('completed_at', '>=', $since)->count(),
            'schema_failures' => ParserDiagnosticReview::query()->where('failure_category', 'schema_validation')->where('failed_at', '>=', $since)->count(),
            'output_limit_failures' => ParserDiagnosticReview::query()->where('failure_category', 'output_token_limit')->where('failed_at', '>=', $since)->count(),
            'stale' => ParserDiagnosticReview::query()->where('status', ParserDiagnosticReviewStatus::Stale)->count(),
            'accepted' => (int) $actionCounts->get(ParserDiagnosticReviewActionType::Accepted->value, 0),
            'rejected' => (int) $actionCounts->get(ParserDiagnosticReviewActionType::Rejected->value, 0),
            'automatic_resolutions' => (int) $actionCounts->get(ParserDiagnosticReviewActionType::AutomaticallyAccepted->value, 0),
            'tokens' => (int) ($usage?->tokens ?? 0),
            'cost_micros' => (int) AiBudgetReservation::query()
                ->where('status', 'settled')
                ->whereNull('parser_execution_id')
                ->where('settled_at', '>=', $since)
                ->sum('actual_micros'),
            'github_duplicates' => (int) ParserBugReport::query()->selectRaw('COALESCE(SUM(CASE WHEN occurrence_count > 1 THEN occurrence_count - 1 ELSE 0 END), 0) as aggregate')->value('aggregate'),
            'github_failures' => ParserBugReport::query()->where('status', ParserBugReportStatus::Failed)->where('last_attempted_at', '>=', $since)->count(),
            'override_invalidations' => ParserReportOverride::query()->whereNotNull('invalidated_at')->count(),
            'budgets' => $this->budgets(),
        ];
    }

    /** @return array<int, array<string, bool|int|string>> */
    private function budgets(): array
    {
        $now = CarbonImmutable::now((string) config('fish.ai_review.budgets.timezone'));

        return collect([AiBudgetPeriodType::Daily, AiBudgetPeriodType::Monthly])->map(function (AiBudgetPeriodType $type) use ($now): array {
            $start = $type === AiBudgetPeriodType::Daily ? $now->toDateString() : $now->startOfMonth()->toDateString();
            $configuredDailyLimit = (int) config('fish.ai_review.budgets.daily_limit_micros');
            $monthlyLimit = (int) config('fish.ai_review.budgets.monthly_limit_micros');
            $limit = $type === AiBudgetPeriodType::Daily && $configuredDailyLimit === 0
                ? $monthlyLimit
                : (int) config("fish.ai_review.budgets.{$type->value}_limit_micros");
            $period = AiBudgetPeriod::query()
                ->where('provider', config('fish.ai_review.provider'))
                ->where('period_type', $type)
                ->whereDate('period_start', $start)
                ->first(['limit_micros', 'reserved_micros', 'spent_micros']);
            $spent = (int) ($period?->spent_micros ?? 0);
            $reserved = (int) ($period?->reserved_micros ?? 0);

            return [
                'period' => $type->value,
                'independent_limit' => $type !== AiBudgetPeriodType::Daily || $configuredDailyLimit > 0,
                'limit_micros' => $limit,
                'spent_micros' => $spent,
                'reserved_micros' => $reserved,
                'remaining_micros' => max(0, $limit - $spent - $reserved),
            ];
        })->all();
    }
}
