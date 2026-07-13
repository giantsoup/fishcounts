<?php

namespace App\Actions\Parsing;

use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\QueueParserDiagnosticReviewResult;
use App\Jobs\ParseRawPayloadJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class QueueParserDiagnosticReview
{
    public function __construct(
        private readonly ExpireStaleParserDiagnosticReviewRuns $expireStaleRuns,
    ) {}

    public function handle(ParserError $parserError, User $requestedBy): QueueParserDiagnosticReviewResult
    {
        $this->ensureDispatchIsAvailable();

        if ($parserError->raw_scrape_payload_id === null) {
            throw ValidationException::withMessages(['review' => 'This parser error does not have the raw payload required for AI review.']);
        }

        $parserErrorId = $parserError->id;
        $payloadId = $parserError->raw_scrape_payload_id;
        $newRunId = null;
        $result = DB::transaction(function () use ($parserErrorId, $payloadId, $requestedBy, &$newRunId): QueueParserDiagnosticReviewResult {
            $payload = RawScrapePayload::query()->lockForUpdate()->findOrFail($payloadId);
            $parserError = ParserError::query()
                ->whereKey($parserErrorId)
                ->whereBelongsTo($payload, 'rawScrapePayload')
                ->lockForUpdate()
                ->first();

            if ($parserError === null) {
                throw ValidationException::withMessages(['review' => 'This parser error changed while the request was starting. Refresh the page and try again.']);
            }

            if ($parserError->resolved_at !== null || $parserError->resolution_type !== null) {
                throw ValidationException::withMessages(['review' => 'This parser error has already been resolved.']);
            }

            $this->expireStaleRuns->handle($payload->id);

            if (filled($parserError->diagnostic_fingerprint)) {
                $existingReview = ParserDiagnosticReview::query()
                    ->where('raw_scrape_payload_id', $parserError->raw_scrape_payload_id)
                    ->where('diagnostic_fingerprint', $parserError->diagnostic_fingerprint)
                    ->latest()
                    ->first();

                if ($existingReview !== null) {
                    if ($existingReview->parser_error_id !== $parserError->id) {
                        $existingReview->forceFill(['parser_error_id' => $parserError->id])->save();
                    }

                    return in_array($existingReview->status, [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running], true)
                        ? QueueParserDiagnosticReviewResult::AlreadyQueued
                        : QueueParserDiagnosticReviewResult::ExistingReview;
                }
            }

            $activeRunExists = ParserDiagnosticReviewRun::query()
                ->whereBelongsTo($payload, 'rawScrapePayload')
                ->whereIn('status', ParserDiagnosticReviewRunStatus::activeValues())
                ->exists();

            if ($activeRunExists) {
                return QueueParserDiagnosticReviewResult::AlreadyQueued;
            }

            $requiresReparse = blank($parserError->diagnostic_fingerprint);
            $run = ParserDiagnosticReviewRun::query()->create([
                'raw_scrape_payload_id' => $payload->id,
                'requested_by_user_id' => $requestedBy->id,
                'status' => $requiresReparse
                    ? ParserDiagnosticReviewRunStatus::Preparing
                    : ParserDiagnosticReviewRunStatus::Queued,
            ]);
            $newRunId = $run->id;

            DB::afterCommit(function () use ($payloadId, $requiresReparse, $run): void {
                try {
                    if ($requiresReparse) {
                        ParseRawPayloadJob::dispatch($payloadId, true, $run->id);
                    } else {
                        ReviewParserDiagnosticsJob::dispatch($payloadId, $run->id);
                    }
                } catch (Throwable $throwable) {
                    $run->refresh()->markFailed($throwable);
                    report($throwable);
                }
            });

            return $requiresReparse
                ? QueueParserDiagnosticReviewResult::ReparseQueued
                : QueueParserDiagnosticReviewResult::ReviewQueued;
        }, attempts: 3);

        if ($newRunId !== null && ParserDiagnosticReviewRun::query()->find($newRunId)?->status === ParserDiagnosticReviewRunStatus::Failed) {
            throw ValidationException::withMessages(['review' => 'The AI review could not be queued. Please try again.']);
        }

        return $result;
    }

    private function ensureDispatchIsAvailable(): void
    {
        if (! config('fish.ai_review.enabled')
            || ! config('fish.ai_review.dispatch_enabled')
            || blank(config('services.openai.api_key'))) {
            throw ValidationException::withMessages(['review' => 'AI review dispatch is unavailable.']);
        }
    }
}
