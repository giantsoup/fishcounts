<?php

namespace App\Actions\Parsing;

use App\Enums\CanonicalEntityType;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserErrorResolutionType;
use App\Models\BoatAlias;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewAction;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\SpeciesAlias;
use App\Models\TripTypeAlias;
use App\Models\User;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReverseAutomatedParserDiagnosticReview
{
    public function __construct(
        private readonly ParseRawPayloadAction $parseRawPayload,
        private readonly TripReportNormalizer $tripReportNormalizer,
    ) {}

    public function handle(
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ParserDiagnosticReviewAction $automaticAction,
        User $actor,
    ): void {
        $payloadIds = DB::transaction(function () use ($parserError, $review, $automaticAction, $actor): array {
            $parserError = ParserError::query()->lockForUpdate()->findOrFail($parserError->id);
            $review = ParserDiagnosticReview::query()->lockForUpdate()->findOrFail($review->id);
            $automaticAction = ParserDiagnosticReviewAction::query()->lockForUpdate()->findOrFail($automaticAction->id);

            if ($automaticAction->parser_diagnostic_review_id !== $review->id
                || $automaticAction->parser_error_id !== $parserError->id
                || $automaticAction->action !== ParserDiagnosticReviewActionType::AutomaticallyAccepted) {
                $this->invalid('This automatic resolution does not belong to the selected diagnostic review.');
            }

            if ($review->humanActions()
                ->where('action', ParserDiagnosticReviewActionType::AutomationReversed)
                ->where('review_attempt', $automaticAction->review_attempt)
                ->exists()) {
                $this->invalid('This automatic resolution has already been reversed.');
            }

            $details = $automaticAction->details ?? [];
            $canonicalType = CanonicalEntityType::tryFrom((string) ($details['canonical_type'] ?? ''));
            $aliasId = $details['alias_id'] ?? null;
            $canonicalId = $details['canonical_id'] ?? null;
            $normalizedAlias = $details['normalized_alias'] ?? null;
            if ($canonicalType === null || ! is_int($aliasId) || ! is_int($canonicalId) || ! is_string($normalizedAlias)) {
                $this->invalid('The automatic resolution audit evidence is incomplete and cannot be reversed safely.');
            }

            $alias = $this->lockedAlias($canonicalType, $aliasId);
            if ($alias === null
                || $alias->getAttribute($this->foreignKey($canonicalType)) !== $canonicalId
                || $alias->getAttribute('normalized_alias') !== $normalizedAlias) {
                $this->invalid('The automatically created alias has changed and cannot be reversed safely.');
            }

            $affectedErrorIds = collect($details['affected_parser_error_ids'] ?? [])
                ->filter(fn (mixed $id): bool => is_int($id))
                ->values()
                ->all();
            $affectedErrors = ParserError::query()
                ->whereKey($affectedErrorIds)
                ->where('resolution_type', ParserErrorResolutionType::AiAssistedAlias)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $alias->delete();
            ParserError::query()->whereKey($affectedErrors->modelKeys())->update([
                'resolved_at' => null,
                'resolved_by_user_id' => null,
                'resolution_type' => null,
            ]);

            ParserDiagnosticReviewAction::query()->create([
                'parser_diagnostic_review_id' => $review->id,
                'parser_error_id' => $parserError->id,
                'actor_user_id' => $actor->id,
                'actor_name' => $actor->name,
                'actor_email' => $actor->email,
                'action' => ParserDiagnosticReviewActionType::AutomationReversed,
                'review_attempt' => $automaticAction->review_attempt,
                'details' => [
                    'automatic_action_id' => $automaticAction->id,
                    'canonical_type' => $canonicalType->value,
                    'canonical_id' => $canonicalId,
                    'alias_id' => $aliasId,
                    'alias' => $details['alias'] ?? null,
                    'normalized_alias' => $normalizedAlias,
                    'reopened_parser_error_ids' => $affectedErrors->modelKeys(),
                    'reparse_payload_ids' => $affectedErrors->pluck('raw_scrape_payload_id')->unique()->values()->all(),
                ],
            ]);

            return $affectedErrors->pluck('raw_scrape_payload_id')->unique()->values()->all();
        }, attempts: 3);

        $dates = collect($payloadIds)
            ->map(function (int $payloadId): ?string {
                $payload = RawScrapePayload::query()->find($payloadId);
                if ($payload === null) {
                    return null;
                }

                $this->parseRawPayload->handle($payload->id, false);

                return $payload->target_date->toDateString();
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->tripReportNormalizer->refreshPrimaryReportsForDates($dates);
    }

    private function lockedAlias(CanonicalEntityType $type, int $id): ?Model
    {
        $model = match ($type) {
            CanonicalEntityType::Boat => BoatAlias::class,
            CanonicalEntityType::Species => SpeciesAlias::class,
            CanonicalEntityType::TripType => TripTypeAlias::class,
        };

        return $model::query()->lockForUpdate()->find($id);
    }

    private function foreignKey(CanonicalEntityType $type): string
    {
        return match ($type) {
            CanonicalEntityType::Boat => 'boat_id',
            CanonicalEntityType::Species => 'species_id',
            CanonicalEntityType::TripType => 'trip_type_id',
        };
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['review' => $message]);
    }
}
