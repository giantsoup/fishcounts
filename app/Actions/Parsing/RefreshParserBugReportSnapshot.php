<?php

namespace App\Actions\Parsing;

use App\DTOs\ParserBugIssueCandidateData;
use App\Enums\ParserBugReportStatus;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;

final class RefreshParserBugReportSnapshot
{
    public function handle(
        ParserBugReport $report,
        ParserDiagnosticReview $review,
        ParserBugIssueCandidateData $candidate,
    ): bool {
        if ($report->issue_number !== null
            || $report->status === ParserBugReportStatus::Invalidated
            || $report->parser_diagnostic_review_id !== $review->id
            || $report->review_attempt !== $review->attempts
            || $candidate->signature !== $report->signature) {
            return false;
        }

        $hasChanged = $candidate->sourceSlug !== $report->source_slug
            || $candidate->title !== $report->title
            || ! hash_equals($candidate->body, $report->body)
            || $candidate->labels !== $report->labels;

        if (! $hasChanged) {
            return false;
        }

        $report->forceFill([
            'source_slug' => $candidate->sourceSlug,
            'status' => $report->requires_approval
                ? ParserBugReportStatus::Preview
                : ParserBugReportStatus::Pending,
            'title' => $candidate->title,
            'body' => $candidate->body,
            'labels' => $candidate->labels,
            'approved_at' => null,
            'approved_by_user_id' => null,
            'approved_by_name' => null,
            'approved_by_email' => null,
            'failure_message' => null,
        ])->save();

        return true;
    }
}
