<?php

namespace App\Actions\Parsing;

use App\Enums\ParserBugReportStatus;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Services\IssueTracking\ParserBugIssueCandidateFactory;
use Illuminate\Validation\ValidationException;

final class InvalidateParserBugReport
{
    public function __construct(private readonly ParserBugIssueCandidateFactory $candidateFactory) {}

    public function handle(ParserDiagnosticReview $review, string $reason): void
    {
        if ($review->parser_bug_report_id === null) {
            return;
        }

        $report = ParserBugReport::query()->lockForUpdate()->find($review->parser_bug_report_id);
        if ($report === null) {
            $review->forceFill(['parser_bug_report_id' => null])->save();

            return;
        }

        $report->occurrences()
            ->where('parser_diagnostic_review_id', $review->id)
            ->where('review_attempt', $review->attempts)
            ->whereNull('invalidated_at')
            ->lockForUpdate()
            ->update([
                'invalidated_at' => now(),
                'invalidation_reason' => $reason,
                'updated_at' => now(),
            ]);

        $review->forceFill(['parser_bug_report_id' => null])->save();
        $this->refreshSummary($report);

        if ($report->issue_number === null
            && $report->parser_diagnostic_review_id === $review->id
            && $report->review_attempt === $review->attempts) {
            $this->rebaseOrInvalidate($report, $reason);
        }
    }

    private function rebaseOrInvalidate(ParserBugReport $report, string $reason): void
    {
        $occurrences = $report->occurrences()
            ->whereNull('invalidated_at')
            ->oldest('seen_at')
            ->get();

        foreach ($occurrences as $occurrence) {
            $review = ParserDiagnosticReview::query()->find($occurrence->parser_diagnostic_review_id);
            if ($review === null
                || $review->attempts !== $occurrence->review_attempt
                || $review->parser_bug_report_id !== $report->id) {
                continue;
            }

            try {
                $candidate = $this->candidateFactory->make($review);
            } catch (ValidationException) {
                continue;
            }

            if ($candidate->signature !== $report->signature) {
                continue;
            }

            $report->forceFill([
                'parser_diagnostic_review_id' => $review->id,
                'review_attempt' => $review->attempts,
                'source_slug' => $candidate->sourceSlug,
                'status' => $report->requires_approval ? ParserBugReportStatus::Preview : ParserBugReportStatus::Pending,
                'title' => $candidate->title,
                'body' => $candidate->body,
                'labels' => $candidate->labels,
                'approved_at' => null,
                'approved_by_user_id' => null,
                'approved_by_name' => null,
                'approved_by_email' => null,
                'invalidated_at' => null,
                'invalidation_reason' => null,
                'failure_message' => null,
            ])->save();

            return;
        }

        $report->forceFill([
            'status' => ParserBugReportStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ])->save();
    }

    private function refreshSummary(ParserBugReport $report): void
    {
        $validOccurrences = $report->occurrences()->whereNull('invalidated_at');
        $count = (clone $validOccurrences)->count();
        $attributes = ['occurrence_count' => $count];

        if ($count > 0) {
            $attributes['first_seen_at'] = (clone $validOccurrences)->min('seen_at');
            $attributes['last_seen_at'] = (clone $validOccurrences)->max('seen_at');
        }

        $report->forceFill($attributes)->save();
    }
}
