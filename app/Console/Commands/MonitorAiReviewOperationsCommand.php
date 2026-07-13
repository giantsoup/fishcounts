<?php

namespace App\Console\Commands;

use App\Actions\Parsing\ExpireStaleParserDiagnosticReviewRuns;
use App\Services\AI\AiReviewMetrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('ai-reviews:monitor')]
#[Description('Record safe operational warnings for AI parser review health')]
class MonitorAiReviewOperationsCommand extends Command
{
    public function handle(
        AiReviewMetrics $metrics,
        ExpireStaleParserDiagnosticReviewRuns $expireStaleRuns,
    ): int {
        $expiredRuns = $expireStaleRuns->handle();
        $snapshot = $metrics->snapshot();
        $warnings = [];

        if ($expiredRuns > 0) {
            $warnings[] = "Expired {$expiredRuns} stalled manual AI review request(s).";
        }

        if ($snapshot['queue_depth'] >= (int) config('fish.ai_review.operations.queue_depth_warning')) {
            $warnings[] = 'AI parser review queue depth is above its warning threshold.';
        }

        if ($snapshot['queue_oldest_age_seconds'] >= (int) config('fish.ai_review.operations.queue_age_warning_minutes') * 60) {
            $warnings[] = 'The oldest AI parser review job is above its age warning threshold.';
        }

        if ($snapshot['github_queue_depth'] >= (int) config('fish.ai_review.operations.queue_depth_warning')) {
            $warnings[] = 'GitHub issue queue depth is above its warning threshold.';
        }

        if ($snapshot['github_queue_oldest_age_seconds'] >= (int) config('fish.ai_review.operations.queue_age_warning_minutes') * 60) {
            $warnings[] = 'The oldest GitHub issue job is above its age warning threshold.';
        }

        if ($snapshot['failed'] >= (int) config('fish.ai_review.operations.failure_warning')) {
            $warnings[] = 'AI parser review failures in the last 24 hours are above their warning threshold.';
        }

        if ($snapshot['stale'] >= (int) config('fish.ai_review.operations.stale_review_warning')) {
            $warnings[] = 'Stale AI parser reviews are above their warning threshold.';
        }

        if ($snapshot['github_failures'] >= (int) config('fish.ai_review.operations.failure_warning')) {
            $warnings[] = 'GitHub issue failures in the last 24 hours are above their warning threshold.';
        }

        foreach ($snapshot['budgets'] as $budget) {
            if ($budget['remaining_micros'] === 0) {
                $warnings[] = "The {$budget['period']} AI parser review budget is exhausted.";
            }
        }

        foreach ($warnings as $warning) {
            Log::warning($warning, [
                'queue_depth' => $snapshot['queue_depth'],
                'queue_oldest_age_seconds' => $snapshot['queue_oldest_age_seconds'],
                'github_queue_depth' => $snapshot['github_queue_depth'],
                'github_queue_oldest_age_seconds' => $snapshot['github_queue_oldest_age_seconds'],
            ]);
            $this->warn($warning);
        }

        if ($warnings === []) {
            $this->info('AI parser review operations are within configured thresholds.');
        }

        return self::SUCCESS;
    }
}
