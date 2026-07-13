<?php

namespace App\Console\Commands;

use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use App\Models\HistoricalAiReviewRun;
use App\Models\HistoricalAiReviewRunItem;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewRun;
use App\Services\AI\AiBudgetManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('ai-reviews:prune')]
#[Description('Prune AI parser diagnostic reviews outside the configured rolling retention window')]
class PruneParserDiagnosticReviewsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AiBudgetManager $budgetManager): int
    {
        $retentionMonths = (int) config('fish.ai_review.retention.complete_months');
        $cutoff = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($retentionMonths);
        $deleted = 0;

        ParserDiagnosticReview::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('humanActions')
            ->whereNull('parser_bug_report_id')
            ->whereDoesntHave('reportOverride')
            ->select('id')
            ->chunkById(500, function (Collection $reviews) use (&$deleted): void {
                $deleted += ParserDiagnosticReview::query()->whereKey($reviews->pluck('id'))->delete();
            });

        $this->info("Pruned {$deleted} parser diagnostic review records.");

        $clearedFailures = ParserDiagnosticReview::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('failure_message')
            ->update(['failure_message' => null]);

        $clearedFailures += ParserBugReport::query()
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('failure_message')
            ->update(['failure_message' => null]);
        $clearedFailures += HistoricalAiReviewRun::query()
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('failure_message')
            ->update(['failure_message' => null]);
        $clearedFailures += HistoricalAiReviewRunItem::query()
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('failure_message')
            ->update(['failure_message' => null]);
        $clearedFailures += ParserDiagnosticReviewRun::query()
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('failure_message')
            ->update(['failure_message' => null]);

        AiBudgetReservation::query()
            ->where('status', 'reserved')
            ->where('expires_at', '<=', now())
            ->select('id')
            ->chunkById(500, function (Collection $reservations) use ($budgetManager): void {
                $reservations->each(fn (AiBudgetReservation $reservation): AiBudgetReservation => $budgetManager->release($reservation));
            });

        $prunedReservations = 0;
        AiBudgetReservation::query()
            ->where('created_at', '<', $cutoff)
            ->where('status', '!=', 'reserved')
            ->select('id')
            ->chunkById(500, function (Collection $reservations) use (&$prunedReservations): void {
                $prunedReservations += AiBudgetReservation::query()->whereKey($reservations->pluck('id'))->delete();
            });

        $prunedPeriods = AiBudgetPeriod::query()
            ->where('period_end', '<', $cutoff->toDateString())
            ->whereDoesntHave('reservations')
            ->whereDoesntHave('dailyReservations')
            ->delete();
        $prunedFailedJobs = DB::table('failed_jobs')->where('failed_at', '<', $cutoff)->delete();

        $this->info("Cleared {$clearedFailures} retained failure messages and pruned {$prunedReservations} budget reservations, {$prunedPeriods} empty periods, and {$prunedFailedJobs} failed jobs.");

        return self::SUCCESS;
    }
}
