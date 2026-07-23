<?php

namespace App\Actions\Parsing;

use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserEngine;
use App\Enums\ParserReportOverrideStatus;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserReportOverride;
use App\Models\RawScrapePayload;
use App\Models\User;
use App\Services\IssueTracking\PublishedParserBugReportValidator;
use App\Services\Parsing\ParserReportOverrideApplier;
use App\Services\Parsing\ParserReportOverrideValidator;
use App\Services\Parsing\TripReportNormalizer;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveParserReportOverride
{
    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly ParserReportOverrideValidator $validator,
        private readonly ParserReportOverrideApplier $applier,
        private readonly PublishedParserBugReportValidator $publishedIssueValidator,
        private readonly ParseRawPayloadAction $parseRawPayload,
        private readonly TripReportNormalizer $normalizer,
    ) {}

    public function handle(ParserReportOverride $override, User $approver, ?string $reviewNotes = null): void
    {
        if (! (bool) config('fish.parsing.overrides.enabled', false)) {
            throw ValidationException::withMessages(['override' => 'Parser report overrides are disabled.']);
        }

        $approved = DB::transaction(function () use ($override, $approver, $reviewNotes): bool {
            $override = ParserReportOverride::query()->lockForUpdate()->findOrFail($override->id);

            if ($override->status !== ParserReportOverrideStatus::Pending) {
                throw ValidationException::withMessages(['override' => 'Only a pending override may be approved.']);
            }

            $review = ParserDiagnosticReview::query()->lockForUpdate()->findOrFail($override->parser_diagnostic_review_id);
            $payload = RawScrapePayload::query()->with('scrapeSource')->lockForUpdate()->findOrFail($override->raw_scrape_payload_id);
            $override->loadMissing('parserBugReport');

            if ($payload->scrapeSource->parser_engine === ParserEngine::Ai) {
                throw ValidationException::withMessages(['override' => 'Parser-version-bound overrides cannot be approved while this source uses AI primary parsing.']);
            }

            if (! in_array($payload->scrapeSource->slug, config('fish.parsing.overrides.allowed_source_slugs', []), true)) {
                throw ValidationException::withMessages(['override' => 'Report overrides are not enabled for this scrape source.']);
            }

            try {
                $this->validateReview($override, $review, $payload);
                $this->publishedIssueValidator->validate($review, $override->parserBugReport);
            } catch (ValidationException) {
                $this->invalidate($override, 'review_or_issue_no_longer_current');

                return false;
            }

            $rawPayload = new RawPayloadData(
                sourceKey: $payload->scrapeSource->slug,
                targetDate: CarbonImmutable::parse($payload->target_date),
                url: $payload->url,
                body: $payload->payload,
                metadata: $payload->metadata ?? [],
            );
            $parsed = $this->registry->forSource($payload->scrapeSource)->parse($rawPayload);

            try {
                $identity = $this->applier->identity($payload, $rawPayload, $parsed, $override->report_index);
            } catch (ValidationException) {
                $this->invalidate($override, 'report_no_longer_exists');

                return false;
            }

            $invalidationReason = $this->identityMismatchReason($override, $identity);
            if ($invalidationReason !== null) {
                $this->invalidate($override, $invalidationReason);

                return false;
            }

            try {
                $corrections = $this->validator->validate($override->corrections, $override->report_index);
                $this->applier->applyCorrections($parsed, $corrections);
            } catch (ValidationException) {
                $this->invalidate($override, 'correction_no_longer_valid');

                return false;
            }

            $hasActiveConflict = ParserReportOverride::query()
                ->whereKeyNot($override->id)
                ->where('raw_scrape_payload_id', $override->raw_scrape_payload_id)
                ->where('report_fingerprint', $override->report_fingerprint)
                ->where('status', ParserReportOverrideStatus::Active)
                ->lockForUpdate()
                ->exists();

            if ($hasActiveConflict) {
                throw ValidationException::withMessages(['override' => 'Another active override already applies to this report.']);
            }

            $override->forceFill([
                'status' => ParserReportOverrideStatus::Active,
                'approved_by_user_id' => $approver->id,
                'approved_by_name' => $approver->name,
                'approved_by_email' => $approver->email,
                'review_notes' => $reviewNotes,
                'approved_at' => now(),
            ])->save();

            $result = $this->parseRawPayload->handle($payload->id, false);
            $this->normalizer->refreshPrimaryReports($payload->target_date->toDateString());

            return $result->rawScrapePayloadId === $payload->id;
        }, attempts: 3);

        if (! $approved) {
            throw ValidationException::withMessages(['override' => 'This override was invalidated and must be proposed again from a fresh review.']);
        }
    }

    /** @param array<string, mixed> $identity */
    private function identityMismatchReason(ParserReportOverride $override, array $identity): ?string
    {
        return match (true) {
            $override->correction_schema_version !== $identity['correction_schema_version'] => 'correction_schema_changed',
            $override->parser_version !== $identity['parser_version'] => 'parser_version_changed',
            $override->paragraph_fingerprint !== $identity['paragraph_fingerprint'] => 'source_paragraph_changed',
            $override->report_fingerprint !== $identity['report_fingerprint'] => 'report_fingerprint_changed',
            default => null,
        };
    }

    private function validateReview(
        ParserReportOverride $override,
        ParserDiagnosticReview $review,
        RawScrapePayload $payload,
    ): void {
        if ($review->status !== ParserDiagnosticReviewStatus::Succeeded
            || $review->attempts !== $override->review_attempt
            || $review->raw_scrape_payload_id !== $payload->id
            || $review->payload_hash === null
            || ! hash_equals($review->payload_hash, $payload->payload_hash)
            || $review->parser_bug_report_id !== $override->parser_bug_report_id) {
            throw ValidationException::withMessages(['override' => 'The source review is stale.']);
        }
    }

    private function invalidate(ParserReportOverride $override, string $reason): void
    {
        $override->forceFill([
            'status' => ParserReportOverrideStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ])->save();
    }
}
