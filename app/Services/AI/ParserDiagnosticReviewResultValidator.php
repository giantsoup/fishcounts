<?php

namespace App\Services\AI;

use App\DTOs\CanonicalCandidateData;
use App\DTOs\ParserDiagnosticCorrectionData;
use App\DTOs\ParserDiagnosticReviewRequestData;
use App\DTOs\ParserDiagnosticReviewResultData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionField;
use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserDiagnosticReviewClassification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

final class ParserDiagnosticReviewResultValidator
{
    /**
     * @param  array<string, mixed>  $result
     *
     * @throws ValidationException
     */
    public function validate(array $result, ParserDiagnosticReviewRequestData $request): ParserDiagnosticReviewResultData
    {
        $allowedResultKeys = ['classification', 'confidence', 'rationale', 'corrections'];

        if (array_diff(array_keys($result), $allowedResultKeys) !== []) {
            throw ValidationException::withMessages(['result' => 'The review result contains unsupported properties.']);
        }

        $validator = Validator::make($result, [
            'classification' => ['required', Rule::enum(ParserDiagnosticReviewClassification::class)],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'rationale' => ['required', 'string', 'max:'.config('fish.ai_review.limits.max_rationale_length')],
            'corrections' => ['present', 'array', 'max:'.config('fish.ai_review.limits.max_corrections')],
            'corrections.*' => [
                'required',
                'array:operation,report_index,field,canonical_type,canonical_id,value,retained_count,released_count',
            ],
            'corrections.*.operation' => ['required', Rule::enum(ParserCorrectionOperation::class)],
            'corrections.*.report_index' => ['required', 'integer', 'min:0'],
            'corrections.*.field' => ['required', Rule::enum(ParserCorrectionField::class)],
            'corrections.*.canonical_type' => ['present', 'nullable', Rule::enum(CanonicalEntityType::class)],
            'corrections.*.canonical_id' => ['present', 'nullable', 'integer', 'min:1'],
            'corrections.*.value' => ['present', 'nullable', 'integer', 'min:0'],
            'corrections.*.retained_count' => ['present', 'nullable', 'integer', 'min:0'],
            'corrections.*.released_count' => ['present', 'nullable', 'integer', 'min:0'],
        ]);

        $validator->after(function (LaravelValidator $validator) use ($result, $request): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($result['corrections'] as $index => $correction) {
                $this->validateCorrection($validator, $correction, $index, $request);
            }

            if ($result['classification'] === ParserDiagnosticReviewClassification::Clean->value && $result['corrections'] !== []) {
                $validator->errors()->add('corrections', 'Clean reviews cannot contain corrections.');
            }
        });

        $validated = $validator->validate();

        return new ParserDiagnosticReviewResultData(
            classification: ParserDiagnosticReviewClassification::from($validated['classification']),
            confidence: (float) $validated['confidence'],
            rationale: $validated['rationale'],
            corrections: array_map(
                fn (array $correction): ParserDiagnosticCorrectionData => new ParserDiagnosticCorrectionData(
                    operation: ParserCorrectionOperation::from($correction['operation']),
                    reportIndex: $correction['report_index'],
                    field: ParserCorrectionField::from($correction['field']),
                    canonicalType: $correction['canonical_type'] === null
                        ? null
                        : CanonicalEntityType::from($correction['canonical_type']),
                    canonicalId: $correction['canonical_id'],
                    value: $correction['value'],
                    retainedCount: $correction['retained_count'],
                    releasedCount: $correction['released_count'],
                ),
                $validated['corrections'],
            ),
        );
    }

    /** @param array<string, mixed> $correction */
    private function validateCorrection(
        LaravelValidator $validator,
        array $correction,
        int $index,
        ParserDiagnosticReviewRequestData $request,
    ): void {
        $operation = ParserCorrectionOperation::from($correction['operation']);
        $field = ParserCorrectionField::from($correction['field']);
        $canonicalType = $correction['canonical_type'] === null
            ? null
            : CanonicalEntityType::from($correction['canonical_type']);
        $canonicalId = $correction['canonical_id'];
        $path = "corrections.{$index}";

        $requiresCanonical = in_array($operation, [
            ParserCorrectionOperation::MapAlias,
            ParserCorrectionOperation::ReplaceEntity,
            ParserCorrectionOperation::SetSpeciesCount,
            ParserCorrectionOperation::RemoveSpeciesCount,
        ], true);

        if ($requiresCanonical && ($canonicalType === null || $canonicalId === null)) {
            $validator->errors()->add("{$path}.canonical_id", 'This operation requires a canonical candidate.');
        }

        if ($canonicalType !== null && $canonicalId !== null && ! $this->candidateExists($request, $canonicalType, $canonicalId)) {
            $validator->errors()->add("{$path}.canonical_id", 'The canonical candidate is unknown or inactive.');
        }

        if (in_array($operation, [ParserCorrectionOperation::MapAlias, ParserCorrectionOperation::ReplaceEntity], true)) {
            $expectedType = match ($field) {
                ParserCorrectionField::Boat => CanonicalEntityType::Boat,
                ParserCorrectionField::Species => CanonicalEntityType::Species,
                ParserCorrectionField::TripType => CanonicalEntityType::TripType,
                ParserCorrectionField::Anglers, ParserCorrectionField::SpeciesCount => null,
            };

            if ($expectedType === null || $canonicalType !== $expectedType) {
                $validator->errors()->add($path, 'Entity corrections must match the canonical type for their field.');
            }
        }

        if ($operation === ParserCorrectionOperation::SetAnglerCount) {
            if ($field !== ParserCorrectionField::Anglers || $correction['value'] === null) {
                $validator->errors()->add($path, 'An angler correction requires the anglers field and a non-negative value.');
            }

            if ($canonicalType !== null || $canonicalId !== null) {
                $validator->errors()->add($path, 'An angler correction cannot reference a canonical entity.');
            }
        }

        if (in_array($operation, [ParserCorrectionOperation::SetSpeciesCount, ParserCorrectionOperation::RemoveSpeciesCount], true)
            && ($field !== ParserCorrectionField::SpeciesCount || $canonicalType !== CanonicalEntityType::Species)) {
            $validator->errors()->add($path, 'A species-count correction must reference a canonical species.');
        }

        if ($operation === ParserCorrectionOperation::SetSpeciesCount
            && ($correction['retained_count'] === null || $correction['released_count'] === null)) {
            $validator->errors()->add($path, 'A species-count correction requires retained and released counts.');
        }

        if ($operation !== ParserCorrectionOperation::SetAnglerCount && $correction['value'] !== null) {
            $validator->errors()->add("{$path}.value", 'Only angler corrections may contain a value.');
        }

        if ($operation !== ParserCorrectionOperation::SetSpeciesCount
            && ($correction['retained_count'] !== null || $correction['released_count'] !== null)) {
            $validator->errors()->add($path, 'Only species-count corrections may contain count values.');
        }
    }

    private function candidateExists(
        ParserDiagnosticReviewRequestData $request,
        CanonicalEntityType $type,
        int $id,
    ): bool {
        foreach ($request->candidates as $candidate) {
            if ($candidate instanceof CanonicalCandidateData && $candidate->type === $type && $candidate->id === $id) {
                return true;
            }
        }

        return false;
    }
}
