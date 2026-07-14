<?php

namespace App\Jobs;

use App\Actions\Parsing\RefreshParserBugReportSnapshot;
use App\Contracts\IssueTracking\IssueTracker;
use App\DTOs\ParserBugIssueCandidateData;
use App\DTOs\ParserBugIssueData;
use App\Enums\ParserBugReportStatus;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Services\IssueTracking\ParserBugIssueCandidateFactory;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateParserBugIssueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 120;

    public function __construct(public int $parserDiagnosticReviewId)
    {
        $this->onConnection('database');
        $this->onQueue('github-issues');
    }

    public function uniqueId(): string
    {
        return (string) $this->parserDiagnosticReviewId;
    }

    public function uniqueVia(): Repository
    {
        return cache()->store('database');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new RateLimited('github-parser-issues'))->releaseAfter(60)];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('fish.github_issues.retry_window_minutes'));
    }

    public function handle(
        ParserBugIssueCandidateFactory $candidateFactory,
        IssueTracker $issueTracker,
        RefreshParserBugReportSnapshot $refreshSnapshot,
    ): void {
        if (! config('fish.github_issues.enabled')) {
            return;
        }

        $review = ParserDiagnosticReview::query()->find($this->parserDiagnosticReviewId);
        if ($review === null) {
            return;
        }

        try {
            $candidate = $candidateFactory->make($review);
        } catch (ValidationException) {
            return;
        }

        $report = Cache::store('database')->lock('parser-bug-preview-allocation', 30)->block(
            5,
            fn (): ParserBugReport => $this->storeCandidate($review, $candidate, $refreshSnapshot),
        );

        Cache::store('database')->lock('parser-bug-report-'.$candidate->signature, 180)->block(
            5,
            function () use ($report, $candidate, $candidateFactory, $issueTracker): void {
                $report->refresh();

                if ($report->issue_number !== null) {
                    $this->syncExistingIssue($report, $issueTracker);

                    return;
                }

                if (! $this->mayWrite($report, $candidate, $candidateFactory)) {
                    return;
                }

                $report->forceFill([
                    'status' => ParserBugReportStatus::Creating,
                    'attempts' => $report->attempts + 1,
                    'last_attempted_at' => now(),
                    'failure_message' => null,
                ])->save();

                try {
                    $issue = $issueTracker->create(new ParserBugIssueData(
                        title: $report->title,
                        body: $report->body,
                        requiredLabels: $report->labels,
                        optionalLabels: [$report->source_slug],
                        assignees: [(string) config('fish.github_issues.assignee')],
                    ));

                    $report->forceFill([
                        'status' => $issue->state === 'closed' ? ParserBugReportStatus::Closed : ParserBugReportStatus::Open,
                        'issue_number' => $issue->number,
                        'issue_url' => $issue->url,
                        'issue_state' => $issue->state,
                        'last_synced_at' => now(),
                        'failure_message' => null,
                    ])->save();
                } catch (Throwable $throwable) {
                    $report->forceFill([
                        'status' => ParserBugReportStatus::Failed,
                        'failure_message' => $this->safeFailureMessage($throwable),
                    ])->save();

                    if ($this->retryable($throwable)) {
                        throw $throwable;
                    }
                }
            },
        );
    }

    public function failed(Throwable $throwable): void
    {
        $report = ParserDiagnosticReview::query()
            ->find($this->parserDiagnosticReviewId)
            ?->parserBugReport;

        if ($report !== null && $report->issue_number === null) {
            $report->forceFill([
                'status' => ParserBugReportStatus::Failed,
                'failure_message' => $this->safeFailureMessage($throwable),
            ])->save();
        }
    }

    private function storeCandidate(
        ParserDiagnosticReview $review,
        ParserBugIssueCandidateData $candidate,
        RefreshParserBugReportSnapshot $refreshSnapshot,
    ): ParserBugReport {
        return DB::transaction(function () use ($review, $candidate, $refreshSnapshot): ParserBugReport {
            $lockedReview = ParserDiagnosticReview::query()->lockForUpdate()->findOrFail($review->id);
            $report = ParserBugReport::query()
                ->where('signature', $candidate->signature)
                ->lockForUpdate()
                ->first();
            $seenAt = $review->completed_at ?? $review->created_at ?? now();

            if ($report === null) {
                $requiresApproval = (bool) config('fish.github_issues.preview_mode')
                    || ParserBugReport::query()->count() < (int) config('fish.github_issues.required_preview_count');
                $report = ParserBugReport::query()->create([
                    'parser_diagnostic_review_id' => $review->id,
                    'review_attempt' => $review->attempts,
                    'signature' => $candidate->signature,
                    'source_slug' => $candidate->sourceSlug,
                    'status' => $requiresApproval ? ParserBugReportStatus::Preview : ParserBugReportStatus::Pending,
                    'requires_approval' => $requiresApproval,
                    'title' => $candidate->title,
                    'body' => $candidate->body,
                    'labels' => $candidate->labels,
                    'occurrence_count' => 0,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                ]);
            }

            $occurrence = $report->occurrences()->firstOrCreate([
                'parser_diagnostic_review_id' => $review->id,
                'review_attempt' => $review->attempts,
            ], [
                'parser_error_id' => $review->parser_error_id,
                'seen_at' => $seenAt,
            ]);

            if ($occurrence->invalidated_at !== null) {
                $this->refreshOccurrenceSummary($report);

                return $report;
            }

            if ($report->status === ParserBugReportStatus::Invalidated) {
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
            }

            $refreshSnapshot->handle($report, $lockedReview, $candidate);

            if ($lockedReview->parser_bug_report_id !== $report->id) {
                $lockedReview->forceFill(['parser_bug_report_id' => $report->id])->save();
            }

            $this->refreshOccurrenceSummary($report);

            return $report;
        }, attempts: 3);
    }

    private function refreshOccurrenceSummary(ParserBugReport $report): void
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

    private function syncExistingIssue(ParserBugReport $report, IssueTracker $issueTracker): void
    {
        if (! $this->writesEnabled()) {
            return;
        }

        try {
            $issue = $issueTracker->get($report->issue_number);
            $report->forceFill([
                'status' => $issue->state === 'closed' ? ParserBugReportStatus::Closed : ParserBugReportStatus::Open,
                'issue_url' => $issue->url,
                'issue_state' => $issue->state,
                'last_synced_at' => now(),
                'failure_message' => null,
            ])->save();
        } catch (Throwable $throwable) {
            $report->forceFill(['failure_message' => $this->safeFailureMessage($throwable)])->save();

            if ($this->retryable($throwable)) {
                throw $throwable;
            }
        }
    }

    private function mayWrite(
        ParserBugReport $report,
        ParserBugIssueCandidateData $candidate,
        ParserBugIssueCandidateFactory $candidateFactory,
    ): bool {
        if (! $this->writesEnabled()
            || $report->status === ParserBugReportStatus::Invalidated
            || $report->occurrence_count < 1) {
            return false;
        }

        $review = ParserDiagnosticReview::query()->find($report->parser_diagnostic_review_id);
        if ($review === null
            || $review->attempts !== $report->review_attempt
            || $review->parser_bug_report_id !== $report->id) {
            return false;
        }

        try {
            $currentCandidate = $candidateFactory->make($review);
        } catch (ValidationException) {
            return false;
        }

        $isCurrent = $candidate->signature === $report->signature
            && $currentCandidate->signature === $report->signature
            && $currentCandidate->title === $report->title
            && hash_equals($currentCandidate->body, $report->body)
            && $currentCandidate->labels === $report->labels;

        return $isCurrent && (! $report->requires_approval || $report->approved_at !== null);
    }

    private function writesEnabled(): bool
    {
        $hasPrivateKey = filled(config('services.github_app.private_key_path'))
            || filled(config('services.github_app.private_key_base64'));

        return (bool) config('fish.github_issues.write_enabled')
            && filled(config('services.github_app.client_id'))
            && (int) config('services.github_app.installation_id') > 0
            && $hasPrivateKey;
    }

    private function retryable(Throwable $throwable): bool
    {
        if ($throwable instanceof ConnectionException) {
            return true;
        }

        if (! $throwable instanceof RequestException) {
            return false;
        }

        $response = $throwable->response;

        return $response->status() === 429
            || $response->serverError()
            || ($response->status() === 403
                && ($response->hasHeader('Retry-After')
                    || $response->header('X-RateLimit-Remaining') === '0'
                    || str_contains(strtolower($response->body()), 'secondary rate limit')));
    }

    private function safeFailureMessage(Throwable $throwable): string
    {
        $message = preg_replace(
            '/(?:Bearer\s+\S+|ghs_[A-Za-z0-9_]+|-----BEGIN[^-]+PRIVATE KEY-----.*?-----END[^-]+PRIVATE KEY-----)/is',
            '[redacted]',
            $throwable->getMessage(),
        ) ?? 'GitHub issue operation failed.';

        return str($message)->limit((int) config('fish.github_issues.limits.max_failure_message_length'), '')->toString();
    }
}
