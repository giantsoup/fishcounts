<?php

namespace App\Services\AI;

use App\Enums\CanonicalEntityType;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Models\ParserDiagnosticReviewAction;

final class ParserAliasAutomationGate
{
    public function isOpen(): bool
    {
        if (! (bool) config('fish.ai_review.automation.enabled')) {
            return false;
        }

        $eligibleFields = collect(CanonicalEntityType::cases())
            ->filter(fn (CanonicalEntityType $type): bool => $this->allows($type))
            ->map(fn (CanonicalEntityType $type): string => $this->field($type))
            ->all();

        if ($eligibleFields === []) {
            return false;
        }

        $reviewedCount = 0;
        $hasIncorrectResult = false;
        $actions = ParserDiagnosticReviewAction::query()
            ->whereIn('action', [
                ParserDiagnosticReviewActionType::Accepted,
                ParserDiagnosticReviewActionType::Rejected,
            ])
            ->with('parserError:id,raw_field')
            ->lazyById();

        foreach ($actions as $action) {
            $classification = data_get($action->details, 'classification');
            $confidence = data_get($action->details, 'confidence');
            $field = data_get($action->details, 'source_field', $action->parserError?->raw_field);

            if ($classification !== ParserDiagnosticReviewClassification::LegitimateAlias->value
                || ! is_numeric($confidence)
                || (float) $confidence < $this->minimumConfidence()
                || ! in_array($field, $eligibleFields, true)) {
                continue;
            }

            $reviewedCount++;
            $hasIncorrectResult = $hasIncorrectResult
                || $action->action === ParserDiagnosticReviewActionType::Rejected;
        }

        return ! $hasIncorrectResult
            && $reviewedCount >= (int) config('fish.ai_review.automation.minimum_human_reviewed_sample');
    }

    public function allows(CanonicalEntityType $type): bool
    {
        return (bool) match ($type) {
            CanonicalEntityType::Boat => config('fish.ai_review.automation.boats_enabled'),
            CanonicalEntityType::Species => config('fish.ai_review.automation.species_enabled'),
            CanonicalEntityType::TripType => config('fish.ai_review.automation.trip_types_enabled'),
        };
    }

    public function minimumConfidence(): float
    {
        return (float) config('fish.ai_review.automation.minimum_confidence');
    }

    private function field(CanonicalEntityType $type): string
    {
        return match ($type) {
            CanonicalEntityType::Boat => 'boat',
            CanonicalEntityType::Species => 'species',
            CanonicalEntityType::TripType => 'trip_type',
        };
    }
}
