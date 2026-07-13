<?php

namespace App\Services\IssueTracking;

use App\Enums\ParserBugReportStatus;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use Illuminate\Validation\ValidationException;

final class PublishedParserBugReportValidator
{
    public function __construct(private readonly ParserBugIssueCandidateFactory $candidateFactory) {}

    public function validate(ParserDiagnosticReview $review, ParserBugReport $report): void
    {
        $candidate = $this->candidateFactory->make($review);
        $expectedUrl = sprintf(
            'https://github.com/%s/issues/%d',
            trim((string) config('fish.github_issues.repository'), '/'),
            $report->issue_number,
        );
        $expectedState = $report->status === ParserBugReportStatus::Open ? 'open' : 'closed';
        $hasCurrentOccurrence = $report->occurrences()
            ->where('parser_diagnostic_review_id', $review->id)
            ->where('review_attempt', $review->attempts)
            ->whereNull('invalidated_at')
            ->exists();

        if (! in_array($report->status, [ParserBugReportStatus::Open, ParserBugReportStatus::Closed], true)
            || $report->issue_number === null
            || $report->issue_number < 1
            || ! hash_equals($expectedUrl, (string) $report->issue_url)
            || $report->issue_state !== $expectedState
            || $review->parser_bug_report_id !== $report->id
            || ! hash_equals($candidate->signature, $report->signature)
            || $candidate->sourceSlug !== $report->source_slug
            || ! $hasCurrentOccurrence) {
            throw ValidationException::withMessages([
                'override' => 'A current published, deduplicated GitHub parser-bug issue for this review is required.',
            ]);
        }
    }
}
