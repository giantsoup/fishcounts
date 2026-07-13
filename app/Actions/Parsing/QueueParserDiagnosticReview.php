<?php

namespace App\Actions\Parsing;

use App\Enums\QueueParserDiagnosticReviewResult;
use App\Jobs\ParseRawPayloadJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QueueParserDiagnosticReview
{
    public function handle(ParserError $parserError): QueueParserDiagnosticReviewResult
    {
        $this->ensureDispatchIsAvailable();

        return DB::transaction(function () use ($parserError): QueueParserDiagnosticReviewResult {
            $parserError = ParserError::query()->lockForUpdate()->findOrFail($parserError->id);

            if ($parserError->resolved_at !== null || $parserError->resolution_type !== null) {
                throw ValidationException::withMessages(['review' => 'This parser error has already been resolved.']);
            }

            if ($parserError->raw_scrape_payload_id === null) {
                throw ValidationException::withMessages(['review' => 'This parser error does not have the raw payload required for AI review.']);
            }

            $payloadId = $parserError->raw_scrape_payload_id;

            if (blank($parserError->diagnostic_fingerprint)) {
                DB::afterCommit(fn () => ParseRawPayloadJob::dispatch($payloadId));

                return QueueParserDiagnosticReviewResult::ReparseQueued;
            }

            $existingReview = ParserDiagnosticReview::query()
                ->where('raw_scrape_payload_id', $parserError->raw_scrape_payload_id)
                ->where('diagnostic_fingerprint', $parserError->diagnostic_fingerprint)
                ->latest()
                ->first();

            if ($existingReview !== null) {
                if ($existingReview->parser_error_id !== $parserError->id) {
                    $existingReview->forceFill(['parser_error_id' => $parserError->id])->save();
                }

                return QueueParserDiagnosticReviewResult::ExistingReview;
            }

            DB::afterCommit(fn () => ReviewParserDiagnosticsJob::dispatch($payloadId));

            return QueueParserDiagnosticReviewResult::ReviewQueued;
        }, attempts: 3);
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
