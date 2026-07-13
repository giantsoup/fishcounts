<?php

namespace App\Actions\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserCorrectionField;
use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\ParserReportOverride;
use App\Models\RawScrapePayload;
use App\Models\User;
use App\Services\IssueTracking\PublishedParserBugReportValidator;
use App\Services\Parsing\ParserReportOverrideApplier;
use App\Services\Parsing\ParserReportOverrideValidator;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProposeParserReportOverride
{
    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly ParserReportOverrideValidator $validator,
        private readonly ParserReportOverrideApplier $applier,
        private readonly PublishedParserBugReportValidator $publishedIssueValidator,
    ) {}

    public function handle(ParserError $parserError, ParserDiagnosticReview $review, User $creator): ParserReportOverride
    {
        if (! (bool) config('fish.parsing.overrides.enabled', false)) {
            throw ValidationException::withMessages(['override' => 'Parser report overrides are disabled.']);
        }

        return DB::transaction(function () use (
            $parserError,
            $review,
            $creator,
        ): ParserReportOverride {
            $review = ParserDiagnosticReview::query()->lockForUpdate()->findOrFail($review->id);
            $parserError = ParserError::query()->lockForUpdate()->findOrFail($parserError->id);
            $payload = RawScrapePayload::query()->with('scrapeSource')->lockForUpdate()->findOrFail($parserError->raw_scrape_payload_id);
            $review->loadMissing('parserBugReport');
            $this->ensureEligible($parserError, $review, $payload);
            $rawPayload = new RawPayloadData(
                sourceKey: $payload->scrapeSource->slug,
                targetDate: CarbonImmutable::parse($payload->target_date),
                url: $payload->url,
                body: $payload->payload,
                metadata: $payload->metadata ?? [],
            );
            $parsed = $this->registry->forSource($payload->scrapeSource)->parse($rawPayload);
            $reportIndex = data_get($parserError->context, 'report_index');

            if (! is_int($reportIndex)) {
                throw ValidationException::withMessages(['override' => 'The diagnostic does not identify one parsed report.']);
            }

            $correctionArrays = $this->prepareCorrections($review, $parserError);
            $corrections = $this->validator->validate($correctionArrays, $reportIndex);
            $identity = $this->applier->identity($payload, $rawPayload, $parsed, $reportIndex);
            $corrected = $this->applier->applyCorrections($parsed, $corrections);
            $originalReport = $this->reportAt($parsed, $reportIndex);
            $correctedReport = $this->reportAt($corrected, $reportIndex);

            return ParserReportOverride::query()->firstOrCreate(
                [
                    'raw_scrape_payload_id' => $payload->id,
                    'parser_diagnostic_review_id' => $review->id,
                    'report_fingerprint' => $identity['report_fingerprint'],
                    'parser_version' => $identity['parser_version'],
                    'paragraph_fingerprint' => $identity['paragraph_fingerprint'],
                    'correction_schema_version' => $identity['correction_schema_version'],
                    'review_attempt' => $review->attempts,
                ],
                [
                    'parser_bug_report_id' => $review->parserBugReport->id,
                    'report_index' => $identity['report_index'],
                    'corrections' => array_map(fn ($correction): array => $correction->toArray(), $corrections),
                    'original_parse' => $this->applier->snapshot($originalReport),
                    'corrected_parse' => $this->applier->snapshot($correctedReport),
                    'affected_scope' => [
                        'raw_scrape_payload_id' => $payload->id,
                        'source' => $payload->scrapeSource->slug,
                        'date' => $payload->target_date->toDateString(),
                    ],
                    'created_by_user_id' => $creator->id,
                    'created_by_name' => $creator->name,
                    'created_by_email' => $creator->email,
                ],
            );
        }, attempts: 3);
    }

    private function ensureEligible(
        ParserError $parserError,
        ParserDiagnosticReview $review,
        RawScrapePayload $payload,
    ): void {
        if ($parserError->resolved_at !== null
            || $review->status !== ParserDiagnosticReviewStatus::Succeeded
            || $review->parser_error_id !== $parserError->id
            || $review->raw_scrape_payload_id !== $parserError->raw_scrape_payload_id
            || $review->diagnostic_fingerprint !== $parserError->diagnostic_fingerprint
            || $review->payload_hash === null
            || ! hash_equals($review->payload_hash, $payload->payload_hash)) {
            throw ValidationException::withMessages(['override' => 'This review is stale or is not an approved correction source.']);
        }

        if (! in_array($payload->scrapeSource->slug, config('fish.parsing.overrides.allowed_source_slugs', []), true)) {
            throw ValidationException::withMessages(['override' => 'Report overrides are not enabled for this scrape source.']);
        }

        if ($review->parserBugReport === null) {
            throw ValidationException::withMessages(['override' => 'A published, deduplicated GitHub parser-bug issue is required.']);
        }

        $this->publishedIssueValidator->validate($review, $review->parserBugReport);
    }

    /** @return array<int, array<string, mixed>> */
    private function prepareCorrections(ParserDiagnosticReview $review, ParserError $parserError): array
    {
        $corrections = $review->validated_result['corrections'] ?? null;

        if (! is_array($corrections) || $corrections === []) {
            throw ValidationException::withMessages(['override' => 'The review does not contain a typed correction.']);
        }

        return collect($corrections)->map(function (mixed $correction) use ($parserError): array {
            if (! is_array($correction)) {
                throw ValidationException::withMessages(['override' => 'The review correction is malformed.']);
            }

            $field = ParserCorrectionField::tryFrom((string) ($correction['field'] ?? ''));
            $operation = ParserCorrectionOperation::tryFrom((string) ($correction['operation'] ?? ''));
            $correction['match_value'] = $field === ParserCorrectionField::Species
                && in_array($operation, [ParserCorrectionOperation::MapAlias, ParserCorrectionOperation::ReplaceEntity], true)
                    ? $parserError->raw_value
                    : null;

            return $correction;
        })->all();
    }

    private function reportAt(ParsedFishCountCollection $parsed, int $reportIndex): ParsedTripReportData
    {
        $report = $parsed->tripReports->get($reportIndex);

        if ($report === null) {
            throw ValidationException::withMessages(['override' => 'The referenced parsed report no longer exists.']);
        }

        return $report;
    }
}
