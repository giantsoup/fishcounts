<?php

namespace App\Actions\Parsing;

use App\Enums\ParserBugReportStatus;
use App\Jobs\CreateParserBugIssueJob;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\User;
use App\Services\IssueTracking\ParserBugIssueCandidateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActOnParserBugReport
{
    public function __construct(private readonly ParserBugIssueCandidateFactory $candidateFactory) {}

    public function prepare(ParserError $parserError, ParserDiagnosticReview $review): void
    {
        if ($review->parser_error_id !== $parserError->id) {
            throw ValidationException::withMessages(['review' => 'This AI review does not belong to the parser error.']);
        }

        CreateParserBugIssueJob::dispatch($review->id);
    }

    public function approve(ParserBugReport $parserBugReport, User $actor): bool
    {
        return DB::transaction(function () use ($parserBugReport, $actor): bool {
            $report = ParserBugReport::query()->lockForUpdate()->findOrFail($parserBugReport->id);

            if ($report->issue_number !== null) {
                return false;
            }

            $this->validateCurrentPreview($report);

            $report->forceFill([
                'status' => ParserBugReportStatus::Pending,
                'approved_at' => $report->approved_at ?? now(),
                'approved_by_user_id' => $report->approved_by_user_id ?? $actor->id,
                'approved_by_name' => $report->approved_by_name ?? $actor->name,
                'approved_by_email' => $report->approved_by_email ?? $actor->email,
                'failure_message' => null,
            ])->save();

            $reviewId = $report->parser_diagnostic_review_id;
            if (is_int($reviewId)) {
                DB::afterCommit(fn () => CreateParserBugIssueJob::dispatch($reviewId));
            }

            return true;
        }, attempts: 3);
    }

    private function validateCurrentPreview(ParserBugReport $report): void
    {
        $review = ParserDiagnosticReview::query()->lockForUpdate()->find($report->parser_diagnostic_review_id);

        if ($report->status === ParserBugReportStatus::Invalidated
            || $review === null
            || $review->attempts !== $report->review_attempt
            || $review->parser_bug_report_id !== $report->id
            || ! $report->occurrences()
                ->where('parser_diagnostic_review_id', $review->id)
                ->where('review_attempt', $review->attempts)
                ->whereNull('invalidated_at')
                ->exists()) {
            throw ValidationException::withMessages(['review' => 'This parser-bug preview is stale or invalidated and must be regenerated.']);
        }

        $candidate = $this->candidateFactory->make($review);
        if ($candidate->signature !== $report->signature
            || $candidate->title !== $report->title
            || ! hash_equals($candidate->body, $report->body)
            || $candidate->labels !== $report->labels) {
            throw ValidationException::withMessages(['review' => 'This parser-bug preview no longer matches the current validated review.']);
        }
    }
}
