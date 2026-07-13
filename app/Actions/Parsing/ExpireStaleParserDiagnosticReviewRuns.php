<?php

namespace App\Actions\Parsing;

use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewRun;
use Illuminate\Database\Eloquent\Builder;

final class ExpireStaleParserDiagnosticReviewRuns
{
    public function handle(?int $rawScrapePayloadId = null): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('fish.ai_review.operations.manual_run_stale_minutes')));
        $query = ParserDiagnosticReviewRun::query()
            ->whereIn('status', ParserDiagnosticReviewRunStatus::activeValues())
            ->where('updated_at', '<=', $cutoff)
            ->when(
                $rawScrapePayloadId !== null,
                fn (Builder $query): Builder => $query->where('raw_scrape_payload_id', $rawScrapePayloadId),
            );

        $expired = 0;
        $expiredPayloadIds = [];
        $query->eachById(function (ParserDiagnosticReviewRun $run) use (&$expired, &$expiredPayloadIds): void {
            $run->markFailed('The AI review request timed out before it could finish. It is safe to start a new review.');

            if ($run->status === ParserDiagnosticReviewRunStatus::Failed) {
                $expired++;
                $expiredPayloadIds[] = $run->raw_scrape_payload_id;
            }
        });

        if ($expiredPayloadIds !== []) {
            ParserDiagnosticReview::query()
                ->whereIn('raw_scrape_payload_id', array_unique($expiredPayloadIds))
                ->whereIn('status', [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running])
                ->where('updated_at', '<=', $cutoff)
                ->update([
                    'status' => ParserDiagnosticReviewStatus::Stale->value,
                    'failure_category' => 'stalled_manual_run',
                    'failure_message' => 'The manual AI review request timed out before it could finish.',
                ]);
        }

        return $expired;
    }
}
