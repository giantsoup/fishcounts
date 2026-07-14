<?php

namespace App\Actions\Parsing;

use App\Actions\Boats\ConsolidateBoatAlias;
use App\Actions\Species\CreateSpeciesAlias;
use App\Actions\TripTypes\CreateTripTypeAlias;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserErrorResolutionType;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewAction;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use App\Models\User;
use App\Services\Parsing\BoatNameNormalizer;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActOnParserDiagnosticReview
{
    public function __construct(
        private readonly ConsolidateBoatAlias $consolidateBoatAlias,
        private readonly CreateSpeciesAlias $createSpeciesAlias,
        private readonly CreateTripTypeAlias $createTripTypeAlias,
        private readonly ParseRawPayloadAction $parseRawPayload,
        private readonly TripReportNormalizer $tripReportNormalizer,
        private readonly InvalidateParserBugReport $invalidateParserBugReport,
    ) {}

    public function accept(ParserError $parserError, ParserDiagnosticReview $review, User $actor): void
    {
        $payloadId = DB::transaction(function () use ($parserError, $review, $actor): int {
            [$parserError, $review] = $this->lockCurrent($parserError, $review);
            [$canonicalType, $canonicalId] = $this->acceptedTarget($parserError, $review);
            $target = $this->activeTarget($canonicalType, $canonicalId);
            $alias = $parserError->raw_value;

            if (! is_string($alias) || blank($alias)) {
                $this->stale('The source alias is no longer available. Retry the AI review.');
            }

            $normalizedAlias = $this->normalize($canonicalType, $alias);
            if ($normalizedAlias === '') {
                $this->stale('The source alias is no longer valid. Retry the AI review.');
            }

            $this->ensureAliasAvailable($canonicalType, $target, $normalizedAlias);

            match ($canonicalType) {
                CanonicalEntityType::Boat => $this->consolidateBoatAlias->handle($target, $alias, $normalizedAlias, $actor->id),
                CanonicalEntityType::Species => $this->createSpeciesAlias->handle($target, $alias, $normalizedAlias, $actor->id),
                CanonicalEntityType::TripType => $this->createTripTypeAlias->handle($target, $alias, $normalizedAlias, $actor->id),
            };

            $this->record($parserError, $review, $actor, ParserDiagnosticReviewActionType::Accepted, [
                'canonical_type' => $canonicalType->value,
                'canonical_id' => $target->getKey(),
                'canonical_name' => $target->getAttribute('name'),
                'alias' => $alias,
            ]);
            $this->invalidateParserBugReport->handle($review, ParserDiagnosticReviewActionType::Accepted->value);

            return $parserError->raw_scrape_payload_id;
        }, attempts: 3);

        $this->parseRawPayload->handle($payloadId, false);
        $payload = $review->rawScrapePayload()->firstOrFail();
        $this->tripReportNormalizer->refreshPrimaryReports($payload->target_date->toDateString());
    }

    public function reject(ParserError $parserError, ParserDiagnosticReview $review, User $actor): bool
    {
        return DB::transaction(function () use ($parserError, $review, $actor): bool {
            [$parserError, $review] = $this->lockCurrent($parserError, $review);

            if ($review->status !== ParserDiagnosticReviewStatus::Succeeded) {
                throw ValidationException::withMessages(['review' => 'Only a completed AI recommendation can be rejected.']);
            }

            $recorded = $this->record($parserError, $review, $actor, ParserDiagnosticReviewActionType::Rejected);
            if ($recorded) {
                $this->invalidateParserBugReport->handle($review, ParserDiagnosticReviewActionType::Rejected->value);
            }

            return $recorded;
        }, attempts: 3);
    }

    public function leaveOpen(ParserError $parserError, ParserDiagnosticReview $review, User $actor): bool
    {
        return $this->recordOpenAction($parserError, $review, $actor, ParserDiagnosticReviewActionType::LeftOpen);
    }

    public function dismiss(ParserError $parserError, ParserDiagnosticReview $review, User $actor): bool
    {
        return DB::transaction(function () use ($parserError, $review, $actor): bool {
            [$parserError, $review] = $this->lockCurrent($parserError, $review);
            $recorded = $this->record($parserError, $review, $actor, ParserDiagnosticReviewActionType::Dismissed);

            if (! $recorded) {
                return false;
            }

            $this->invalidateParserBugReport->handle($review, ParserDiagnosticReviewActionType::Dismissed->value);

            $parserError->forceFill([
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolution_type' => ParserErrorResolutionType::Dismissed,
            ])->save();

            return true;
        }, attempts: 3);
    }

    public function retry(ParserError $parserError, ParserDiagnosticReview $review, User $actor): bool
    {
        if (! config('fish.ai_review.enabled') || ! config('fish.ai_review.dispatch_enabled')) {
            throw ValidationException::withMessages(['review' => 'AI review dispatch is disabled.']);
        }

        return DB::transaction(function () use ($parserError, $review, $actor): bool {
            [$parserError, $review] = $this->lockCurrent($parserError, $review);

            if (in_array($review->status, [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running], true)) {
                throw ValidationException::withMessages(['review' => 'This AI review is already queued or running.']);
            }

            $recorded = $this->record($parserError, $review, $actor, ParserDiagnosticReviewActionType::Retried, [
                'previous_status' => $review->status->value,
                'previous_result' => $review->validated_result,
                'previous_failure_message' => $review->failure_message,
            ]);

            if (! $recorded) {
                return false;
            }

            $this->invalidateParserBugReport->handle($review, ParserDiagnosticReviewActionType::Retried->value);
            $review->prepareForRetry();
            DB::afterCommit(fn () => DispatchParserDiagnosticReviewBatchesJob::dispatch($parserError->raw_scrape_payload_id));

            return true;
        }, attempts: 3);
    }

    private function recordOpenAction(
        ParserError $parserError,
        ParserDiagnosticReview $review,
        User $actor,
        ParserDiagnosticReviewActionType $action,
    ): bool {
        return DB::transaction(function () use ($parserError, $review, $actor, $action): bool {
            [$parserError, $review] = $this->lockCurrent($parserError, $review);

            return $this->record($parserError, $review, $actor, $action);
        }, attempts: 3);
    }

    /** @return array{ParserError, ParserDiagnosticReview} */
    private function lockCurrent(ParserError $parserError, ParserDiagnosticReview $review): array
    {
        $parserError = ParserError::query()->lockForUpdate()->findOrFail($parserError->id);
        $review = ParserDiagnosticReview::query()->lockForUpdate()->findOrFail($review->id);

        if ($parserError->resolved_at !== null) {
            throw ValidationException::withMessages(['review' => 'This parser error has already been resolved.']);
        }

        if ($review->parser_error_id !== $parserError->id
            || $review->raw_scrape_payload_id !== $parserError->raw_scrape_payload_id
            || $review->diagnostic_fingerprint !== $parserError->diagnostic_fingerprint) {
            $this->stale('This recommendation is stale because the diagnostic changed. Retry the AI review.');
        }

        return [$parserError, $review];
    }

    /** @return array{CanonicalEntityType, int} */
    private function acceptedTarget(ParserError $parserError, ParserDiagnosticReview $review): array
    {
        if ($review->status !== ParserDiagnosticReviewStatus::Succeeded
            || $review->classification !== ParserDiagnosticReviewClassification::LegitimateAlias) {
            $this->stale('Only a successful existing-alias recommendation can be accepted.');
        }

        $corrections = collect($review->validated_result['corrections'] ?? [])
            ->filter(fn (array $correction): bool => ($correction['operation'] ?? null) === ParserCorrectionOperation::MapAlias->value)
            ->values();

        if ($corrections->count() !== 1) {
            $this->stale('The recommendation no longer has one unambiguous alias target. Retry the AI review.');
        }

        $correction = $corrections->first();
        $canonicalType = CanonicalEntityType::tryFrom((string) ($correction['canonical_type'] ?? ''));
        $canonicalId = $correction['canonical_id'] ?? null;
        $expectedType = match ($parserError->raw_field) {
            'boat' => CanonicalEntityType::Boat,
            'species' => CanonicalEntityType::Species,
            'trip_type' => CanonicalEntityType::TripType,
            default => null,
        };

        if ($canonicalType === null || $canonicalType !== $expectedType || ! is_int($canonicalId)) {
            $this->stale('The recommendation target no longer matches this diagnostic. Retry the AI review.');
        }

        return [$canonicalType, $canonicalId];
    }

    private function activeTarget(CanonicalEntityType $canonicalType, int $canonicalId): Model
    {
        $model = match ($canonicalType) {
            CanonicalEntityType::Boat => Boat::class,
            CanonicalEntityType::Species => Species::class,
            CanonicalEntityType::TripType => TripType::class,
        };
        $target = $model::query()->lockForUpdate()->find($canonicalId);

        if ($target === null || ! $target->is_active) {
            $this->stale('The recommendation target is no longer active. Retry the AI review.');
        }

        return $target;
    }

    private function normalize(CanonicalEntityType $canonicalType, string $alias): string
    {
        if ($canonicalType === CanonicalEntityType::Boat) {
            return BoatNameNormalizer::normalize($alias);
        }

        return str($alias)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function ensureAliasAvailable(CanonicalEntityType $canonicalType, Model $target, string $normalizedAlias): void
    {
        [$aliasModel, $foreignKey] = match ($canonicalType) {
            CanonicalEntityType::Boat => [BoatAlias::class, 'boat_id'],
            CanonicalEntityType::Species => [SpeciesAlias::class, 'species_id'],
            CanonicalEntityType::TripType => [TripTypeAlias::class, 'trip_type_id'],
        };
        $existingTargetId = $aliasModel::query()
            ->where('normalized_alias', $normalizedAlias)
            ->lockForUpdate()
            ->value($foreignKey);

        if ($existingTargetId !== null && $existingTargetId !== $target->getKey()) {
            $this->stale('This alias now belongs to a different canonical target. Retry the AI review.');
        }
    }

    /** @param array<string, mixed> $details */
    private function record(
        ParserError $parserError,
        ParserDiagnosticReview $review,
        User $actor,
        ParserDiagnosticReviewActionType $action,
        array $details = [],
    ): bool {
        $audit = ParserDiagnosticReviewAction::query()->firstOrCreate([
            'parser_diagnostic_review_id' => $review->id,
            'action' => $action->value,
            'review_attempt' => $review->attempts,
        ], [
            'parser_error_id' => $parserError->id,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'details' => [
                'diagnostic_fingerprint' => $review->diagnostic_fingerprint,
                'source_field' => $parserError->raw_field,
                'review_status' => $review->status->value,
                'classification' => $review->classification?->value,
                'confidence' => $review->confidence,
                ...$details,
            ],
        ]);

        return $audit->wasRecentlyCreated;
    }

    private function stale(string $message): never
    {
        throw ValidationException::withMessages(['review' => $message]);
    }
}
