<?php

namespace App\Actions\Parsing;

use App\Actions\Boats\ConsolidateBoatAlias;
use App\Actions\Species\CreateSpeciesAlias;
use App\Actions\TripTypes\CreateTripTypeAlias;
use App\DTOs\CanonicalCandidateData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserEngine;
use App\Enums\ParserErrorResolutionType;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewAction;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use App\Services\AI\ParserAliasAutomationGate;
use App\Services\AI\ParserDiagnosticReviewRequestFactory;
use App\Services\Parsing\BoatNameNormalizer;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AutomateParserDiagnosticReviews
{
    public function __construct(
        private readonly ParserAliasAutomationGate $gate,
        private readonly ParserDiagnosticReviewRequestFactory $requestFactory,
        private readonly ConsolidateBoatAlias $consolidateBoatAlias,
        private readonly CreateSpeciesAlias $createSpeciesAlias,
        private readonly CreateTripTypeAlias $createTripTypeAlias,
        private readonly ParseRawPayloadAction $parseRawPayload,
        private readonly TripReportNormalizer $tripReportNormalizer,
        private readonly InvalidateParserBugReport $invalidateParserBugReport,
    ) {}

    /** @param list<int> $reviewIds */
    public function handle(int $rawScrapePayloadId, array $reviewIds): int
    {
        if ($reviewIds === [] || ! $this->gate->isOpen()) {
            return 0;
        }

        try {
            $result = Cache::lock(
                'parser-alias-automation',
                (int) config('fish.ai_review.automation.lock_seconds'),
            )->block(
                (int) config('fish.ai_review.automation.lock_wait_seconds'),
                fn (): array => $this->apply($rawScrapePayloadId, $reviewIds),
            );
        } catch (LockTimeoutException) {
            return 0;
        }

        return $result['applied'];
    }

    /**
     * @param  list<int>  $reviewIds
     * @return array{applied: int, payload_ids: list<int>}
     */
    private function apply(int $rawScrapePayloadId, array $reviewIds): array
    {
        return DB::transaction(function () use ($rawScrapePayloadId, $reviewIds): array {
            $payload = RawScrapePayload::query()->lockForUpdate()->find($rawScrapePayloadId);
            if ($payload === null || ! $this->gate->isOpen()) {
                return ['applied' => 0, 'payload_ids' => []];
            }

            $reviews = ParserDiagnosticReview::query()
                ->whereBelongsTo($payload, 'rawScrapePayload')
                ->whereKey($reviewIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $applied = 0;
            $affectedPayloadIds = collect();

            foreach ($reviews as $review) {
                $reviewPayloadIds = $this->applyReview($payload, $review);
                if ($reviewPayloadIds === null) {
                    continue;
                }

                $applied++;
                $affectedPayloadIds->push(...$reviewPayloadIds);
            }

            if ($applied === 0) {
                return ['applied' => 0, 'payload_ids' => []];
            }

            $payloadIds = $affectedPayloadIds->unique()->values()->all();
            $affectedPayloads = RawScrapePayload::query()
                ->with('scrapeSource:id,parser_engine')
                ->whereKey($payloadIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dates = [];

            foreach ($affectedPayloads as $affectedPayload) {
                if ($affectedPayload->scrapeSource->parser_engine === ParserEngine::Ai) {
                    continue;
                }

                $this->parseRawPayload->handle($affectedPayload->id, false);
                $this->relinkReviews($affectedPayload);
                $dates[] = $affectedPayload->target_date->toDateString();
            }

            $this->tripReportNormalizer->refreshPrimaryReportsForDates(collect($dates)->unique()->values()->all());

            return [
                'applied' => $applied,
                'payload_ids' => $payloadIds,
            ];
        }, attempts: 3);
    }

    /** @return null|list<int> */
    private function applyReview(RawScrapePayload $payload, ParserDiagnosticReview $review): ?array
    {
        $parserError = ParserError::query()->lockForUpdate()->find($review->parser_error_id);
        if ($parserError === null || ! $this->isCurrent($payload, $parserError, $review)) {
            return null;
        }

        $targetReference = $this->targetReference($parserError, $review);
        if ($targetReference === null) {
            return null;
        }

        [$canonicalType, $canonicalId] = $targetReference;
        if (! $this->gate->allows($canonicalType)) {
            return null;
        }

        $request = $this->requestFactory->make($payload, $parserError);
        $candidate = collect($request->candidates)->first(
            fn (CanonicalCandidateData $candidate): bool => $candidate->type === $canonicalType
                && $candidate->id === $canonicalId,
        );
        if (! $candidate instanceof CanonicalCandidateData) {
            return null;
        }

        $target = $this->activeTarget($canonicalType, $canonicalId);
        if ($target === null) {
            return null;
        }

        $alias = is_string($parserError->raw_value) ? Str::squish($parserError->raw_value) : '';
        $normalizedAlias = $this->normalize($canonicalType, $alias);
        if ($alias === '' || $normalizedAlias === '' || $this->aliasExists($canonicalType, $normalizedAlias)) {
            return null;
        }

        if ($canonicalType === CanonicalEntityType::Boat && $this->wouldConsolidateBoat($target, $alias)) {
            return null;
        }

        $affectedErrors = $this->matchingOpenErrors($canonicalType, $normalizedAlias);
        $aliasModel = match ($canonicalType) {
            CanonicalEntityType::Boat => $this->consolidateBoatAlias->handle(
                $target,
                $alias,
                $normalizedAlias,
                null,
                ParserErrorResolutionType::AiAssistedAlias,
                false,
            ),
            CanonicalEntityType::Species => $this->createSpeciesAlias->handle(
                $target,
                $alias,
                $normalizedAlias,
                null,
                ParserErrorResolutionType::AiAssistedAlias,
            ),
            CanonicalEntityType::TripType => $this->createTripTypeAlias->handle(
                $target,
                $alias,
                $normalizedAlias,
                null,
                ParserErrorResolutionType::AiAssistedAlias,
            ),
        };

        $audit = ParserDiagnosticReviewAction::query()->firstOrCreate([
            'parser_diagnostic_review_id' => $review->id,
            'action' => ParserDiagnosticReviewActionType::AutomaticallyAccepted,
            'review_attempt' => $review->attempts,
        ], [
            'parser_error_id' => $parserError->id,
            'actor_user_id' => null,
            'actor_name' => config('fish.ai_review.automation.actor_name'),
            'actor_email' => config('fish.ai_review.automation.actor_email'),
            'details' => $this->evidence($payload, $parserError, $review, $candidate, $aliasModel, $affectedErrors),
        ]);

        if (! $audit->wasRecentlyCreated) {
            return null;
        }

        $this->invalidateParserBugReport->handle($review, ParserDiagnosticReviewActionType::AutomaticallyAccepted->value);

        return $affectedErrors->pluck('raw_scrape_payload_id')->unique()->values()->all();
    }

    private function isCurrent(
        RawScrapePayload $payload,
        ParserError $parserError,
        ParserDiagnosticReview $review,
    ): bool {
        $freshAfter = now()->subHours((int) config('fish.ai_review.automation.freshness_hours'));

        return $parserError->resolved_at === null
            && $parserError->raw_scrape_payload_id === $payload->id
            && $review->raw_scrape_payload_id === $payload->id
            && $review->payload_hash !== null
            && hash_equals($review->payload_hash, $payload->payload_hash)
            && $review->parser_error_id === $parserError->id
            && $review->diagnostic_fingerprint === $parserError->diagnostic_fingerprint
            && $review->status === ParserDiagnosticReviewStatus::Succeeded
            && $review->classification === ParserDiagnosticReviewClassification::LegitimateAlias
            && (float) $review->confidence >= $this->gate->minimumConfidence()
            && $review->completed_at !== null
            && $review->completed_at->gte($freshAfter)
            && $review->completed_at->lte(now())
            && ! $review->humanActions()
                ->where('action', ParserDiagnosticReviewActionType::AutomaticallyAccepted)
                ->where('review_attempt', $review->attempts)
                ->exists();
    }

    /** @return null|array{CanonicalEntityType, int} */
    private function targetReference(ParserError $parserError, ParserDiagnosticReview $review): ?array
    {
        $result = $review->validated_result;
        if (! is_array($result)
            || ($result['classification'] ?? null) !== ParserDiagnosticReviewClassification::LegitimateAlias->value
            || ! is_numeric($result['confidence'] ?? null)
            || (float) $result['confidence'] < $this->gate->minimumConfidence()) {
            return null;
        }

        $corrections = $result['corrections'] ?? null;
        if (! is_array($corrections) || count($corrections) !== 1 || ! is_array($corrections[0])) {
            return null;
        }

        $correction = $corrections[0];
        $canonicalType = CanonicalEntityType::tryFrom((string) ($correction['canonical_type'] ?? ''));
        $canonicalId = $correction['canonical_id'] ?? null;
        $expectedType = match ($parserError->raw_field) {
            'boat' => CanonicalEntityType::Boat,
            'species' => CanonicalEntityType::Species,
            'trip_type' => CanonicalEntityType::TripType,
            default => null,
        };

        if (($correction['operation'] ?? null) !== ParserCorrectionOperation::MapAlias->value
            || $canonicalType === null
            || $canonicalType !== $expectedType
            || ! is_int($canonicalId)) {
            return null;
        }

        return [$canonicalType, $canonicalId];
    }

    private function activeTarget(CanonicalEntityType $canonicalType, int $canonicalId): ?Model
    {
        $model = match ($canonicalType) {
            CanonicalEntityType::Boat => Boat::class,
            CanonicalEntityType::Species => Species::class,
            CanonicalEntityType::TripType => TripType::class,
        };
        $target = $model::query()->lockForUpdate()->find($canonicalId);

        return $target !== null && $target->is_active ? $target : null;
    }

    private function aliasExists(CanonicalEntityType $canonicalType, string $normalizedAlias): bool
    {
        $aliasModel = match ($canonicalType) {
            CanonicalEntityType::Boat => BoatAlias::class,
            CanonicalEntityType::Species => SpeciesAlias::class,
            CanonicalEntityType::TripType => TripTypeAlias::class,
        };

        return $aliasModel::query()
            ->where('normalized_alias', $normalizedAlias)
            ->lockForUpdate()
            ->exists();
    }

    private function wouldConsolidateBoat(Model $target, string $alias): bool
    {
        return Boat::query()
            ->whereKeyNot($target->getKey())
            ->where('slug', Str::slug($alias))
            ->lockForUpdate()
            ->exists();
    }

    /** @return Collection<int, ParserError> */
    private function matchingOpenErrors(CanonicalEntityType $canonicalType, string $normalizedAlias): Collection
    {
        [$errorType, $field] = match ($canonicalType) {
            CanonicalEntityType::Boat => ['unknown_boat_alias', 'boat'],
            CanonicalEntityType::Species => ['unknown_species_alias', 'species'],
            CanonicalEntityType::TripType => ['unknown_trip_type_alias', 'trip_type'],
        };

        return ParserError::query()
            ->whereNull('resolved_at')
            ->where('error_type', $errorType)
            ->where('raw_field', $field)
            ->whereNotNull('raw_value')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'raw_scrape_payload_id', 'raw_value'])
            ->filter(fn (ParserError $error): bool => $this->normalize($canonicalType, $error->raw_value) === $normalizedAlias)
            ->values();
    }

    private function normalize(CanonicalEntityType $canonicalType, string $alias): string
    {
        return $canonicalType === CanonicalEntityType::Boat
            ? BoatNameNormalizer::normalize($alias)
            : str($alias)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function relinkReviews(RawScrapePayload $payload): void
    {
        ParserError::query()
            ->whereBelongsTo($payload, 'rawScrapePayload')
            ->whereNotNull('diagnostic_fingerprint')
            ->orderBy('id')
            ->get(['id', 'diagnostic_fingerprint'])
            ->each(function (ParserError $parserError) use ($payload): void {
                ParserDiagnosticReview::query()
                    ->whereBelongsTo($payload, 'rawScrapePayload')
                    ->where('diagnostic_fingerprint', $parserError->diagnostic_fingerprint)
                    ->update(['parser_error_id' => $parserError->id]);
            });
    }

    /**
     * @param  Collection<int, ParserError>  $affectedErrors
     * @return array<string, mixed>
     */
    private function evidence(
        RawScrapePayload $payload,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        CanonicalCandidateData $candidate,
        Model $alias,
        Collection $affectedErrors,
    ): array {
        return [
            'resolution_type' => ParserErrorResolutionType::AiAssistedAlias->value,
            'diagnostic_fingerprint' => $review->diagnostic_fingerprint,
            'payload_id' => $payload->id,
            'payload_hash' => $review->payload_hash,
            'review_id' => $review->id,
            'review_attempt' => $review->attempts,
            'review_status' => $review->status->value,
            'classification' => $review->classification->value,
            'confidence' => $review->confidence,
            'completed_at' => $review->completed_at?->toISOString(),
            'canonical_type' => $candidate->type->value,
            'canonical_id' => $candidate->id,
            'canonical_name' => $candidate->name,
            'candidate' => $candidate->toArray(),
            'alias_id' => $alias->getKey(),
            'alias' => $alias->getAttribute('alias'),
            'normalized_alias' => $alias->getAttribute('normalized_alias'),
            'source_field' => $parserError->raw_field,
            'source_context' => $parserError->context,
            'rationale' => $review->rationale,
            'provider' => $review->provider,
            'model' => $review->model,
            'prompt_version' => $review->prompt_version,
            'schema_version' => $review->schema_version,
            'affected_parser_error_ids' => $affectedErrors->modelKeys(),
            'affected_payload_ids' => $affectedErrors->pluck('raw_scrape_payload_id')->unique()->values()->all(),
        ];
    }
}
