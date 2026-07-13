<?php

namespace App\Services\Parsing;

use App\DTOs\ParserReportOverrideData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionField;
use App\Enums\ParserCorrectionOperation;
use App\Models\Boat;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

final class ParserReportOverrideValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $corrections
     * @return list<ParserReportOverrideData>
     *
     * @throws ValidationException
     */
    public function validate(array $corrections, int $expectedReportIndex): array
    {
        $maximumCount = (int) config('fish.parsing.overrides.max_count', 1_000_000);
        $maximumAnglers = (int) config('fish.parsing.overrides.max_anglers', 65_535);
        $validator = Validator::make(['corrections' => $corrections], [
            'corrections' => ['required', 'array', 'min:1', 'max:20'],
            'corrections.*' => ['required', 'array:operation,report_index,field,canonical_type,canonical_id,value,retained_count,released_count,match_value'],
            'corrections.*.operation' => ['required', Rule::enum(ParserCorrectionOperation::class)],
            'corrections.*.report_index' => ['required', 'integer', Rule::in([$expectedReportIndex])],
            'corrections.*.field' => ['required', Rule::enum(ParserCorrectionField::class)],
            'corrections.*.canonical_type' => ['present', 'nullable', Rule::enum(CanonicalEntityType::class)],
            'corrections.*.canonical_id' => ['present', 'nullable', 'integer', 'min:1'],
            'corrections.*.value' => ['present', 'nullable', 'integer', 'min:0', "max:{$maximumAnglers}"],
            'corrections.*.retained_count' => ['present', 'nullable', 'integer', 'min:0', "max:{$maximumCount}"],
            'corrections.*.released_count' => ['present', 'nullable', 'integer', 'min:0', "max:{$maximumCount}"],
            'corrections.*.match_value' => ['present', 'nullable', 'string', 'max:60', 'not_regex:/<[^>]+>|https?:\/\/|\b(?:select|insert|update|delete|drop|alter|exec)\b/i'],
        ]);

        $validator->after(function (LaravelValidator $validator) use ($corrections): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($corrections as $index => $correction) {
                $this->validateCorrection($validator, $correction, $index);
            }
        });

        $validated = $validator->validate();

        return array_map(
            fn (array $correction): ParserReportOverrideData => ParserReportOverrideData::fromArray($correction),
            $validated['corrections'],
        );
    }

    /** @param array<string, mixed> $correction */
    private function validateCorrection(LaravelValidator $validator, array $correction, int $index): void
    {
        $operation = ParserCorrectionOperation::from($correction['operation']);
        $field = ParserCorrectionField::from($correction['field']);
        $canonicalType = $correction['canonical_type'] === null ? null : CanonicalEntityType::from($correction['canonical_type']);
        $canonicalId = $correction['canonical_id'];
        $path = "corrections.{$index}";
        $allowedOperations = [
            ParserCorrectionOperation::MapAlias,
            ParserCorrectionOperation::ReplaceEntity,
            ParserCorrectionOperation::SetAnglerCount,
            ParserCorrectionOperation::SetSpeciesCount,
        ];

        if (! in_array($operation, $allowedOperations, true)) {
            $validator->errors()->add("{$path}.operation", 'This correction operation is not allowed for report overrides.');

            return;
        }

        if ($operation === ParserCorrectionOperation::SetAnglerCount) {
            if ($field !== ParserCorrectionField::Anglers || $correction['value'] === null) {
                $validator->errors()->add($path, 'An angler correction requires only a bounded nonnegative value.');
            }

            if ($canonicalType !== null || $canonicalId !== null || $correction['match_value'] !== null) {
                $validator->errors()->add($path, 'An angler correction cannot reference a canonical entity or match value.');
            }

            $this->rejectCountValues($validator, $correction, $path);

            return;
        }

        if ($operation === ParserCorrectionOperation::SetSpeciesCount) {
            if ($field !== ParserCorrectionField::SpeciesCount || $canonicalType !== CanonicalEntityType::Species || $canonicalId === null) {
                $validator->errors()->add($path, 'A count correction must reference an existing species selection.');
            }

            if ($correction['retained_count'] === null || $correction['released_count'] === null) {
                $validator->errors()->add($path, 'Retained and released counts are both required.');
            }

            if ($correction['value'] !== null || $correction['match_value'] !== null) {
                $validator->errors()->add($path, 'A count correction cannot contain an angler value or match value.');
            }

            if ($canonicalType === CanonicalEntityType::Species && $canonicalId !== null) {
                $this->ensureActiveTarget($validator, $canonicalType, (int) $canonicalId, $path);
            }

            return;
        }

        $expectedType = match ($field) {
            ParserCorrectionField::Boat => CanonicalEntityType::Boat,
            ParserCorrectionField::Species => CanonicalEntityType::Species,
            ParserCorrectionField::TripType => CanonicalEntityType::TripType,
            default => null,
        };

        if ($expectedType === null || $canonicalType !== $expectedType || $canonicalId === null) {
            $validator->errors()->add($path, 'An entity correction must reference an existing canonical record of the correct type.');
        } else {
            $this->ensureActiveTarget($validator, $canonicalType, (int) $canonicalId, $path);
        }

        if ($field === ParserCorrectionField::Species && blank($correction['match_value'])) {
            $validator->errors()->add("{$path}.match_value", 'A species-selection correction must identify the current parsed selection.');
        }

        if ($field !== ParserCorrectionField::Species && $correction['match_value'] !== null) {
            $validator->errors()->add("{$path}.match_value", 'Only a species-selection correction may contain a match value.');
        }

        if ($correction['value'] !== null) {
            $validator->errors()->add("{$path}.value", 'Only an angler correction may contain a value.');
        }

        $this->rejectCountValues($validator, $correction, $path);
    }

    /** @param array<string, mixed> $correction */
    private function rejectCountValues(LaravelValidator $validator, array $correction, string $path): void
    {
        if ($correction['retained_count'] !== null || $correction['released_count'] !== null) {
            $validator->errors()->add($path, 'Only a species-count correction may contain count values.');
        }
    }

    private function ensureActiveTarget(
        LaravelValidator $validator,
        CanonicalEntityType $canonicalType,
        int $canonicalId,
        string $path,
    ): void {
        $model = match ($canonicalType) {
            CanonicalEntityType::Boat => Boat::class,
            CanonicalEntityType::Species => Species::class,
            CanonicalEntityType::TripType => TripType::class,
        };
        /** @var Model|null $target */
        $target = $model::query()->find($canonicalId);

        if ($target === null || ! (bool) $target->getAttribute('is_active')) {
            $validator->errors()->add("{$path}.canonical_id", 'The canonical target is missing or inactive.');
        }
    }
}
