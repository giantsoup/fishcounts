<?php

namespace App\Services\AI;

use App\DTOs\ParserDiagnosticReviewRequestData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserDiagnosticType;
use App\Models\Boat;
use App\Models\RawScrapePayload;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

final class ParserDiagnosticReviewRequestValidator
{
    /** @throws ValidationException */
    public function validate(ParserDiagnosticReviewRequestData $request): void
    {
        $data = $request->toArray();
        $validator = Validator::make($data, [
            'payload_id' => ['required', 'integer', 'min:1', 'exists:raw_scrape_payloads,id'],
            'payload_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'diagnostic_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'diagnostic_type' => ['required', Rule::enum(ParserDiagnosticType::class)],
            'field' => ['required', 'string', 'max:100'],
            'raw_value' => ['present', 'nullable', 'string', 'max:2000'],
            'context' => ['required', 'array'],
            'candidates' => ['present', 'array', 'max:'.config('fish.ai_review.limits.max_candidates')],
            'candidates.*' => ['required', 'array:type,id,name,aliases'],
            'candidates.*.type' => ['required', Rule::enum(CanonicalEntityType::class)],
            'candidates.*.id' => ['required', 'integer', 'min:1'],
            'candidates.*.name' => ['required', 'string', 'max:255'],
            'candidates.*.aliases' => ['present', 'array', 'max:20'],
            'candidates.*.aliases.*' => ['required', 'string', 'max:255'],
        ]);

        $validator->after(function (LaravelValidator $validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $seenCandidates = [];
            $diagnosticType = ParserDiagnosticType::from($data['diagnostic_type']);
            $allowedTypes = $this->allowedCandidateTypes($data['field'], $diagnosticType);
            $activeCandidateKeys = $this->activeCandidateKeys($data['candidates']);
            $payloadHash = RawScrapePayload::query()->whereKey($data['payload_id'])->value('payload_hash');

            if (! is_string($payloadHash) || ! hash_equals($payloadHash, $data['payload_hash'])) {
                $validator->errors()->add('payload_hash', 'The payload hash does not match the selected payload.');
            }

            if (! in_array($data['field'], $this->allowedFields($diagnosticType), true)) {
                $validator->errors()->add('field', 'The field is not valid for this diagnostic type.');
            }

            foreach ($data['candidates'] as $index => $candidate) {
                $key = "{$candidate['type']}:{$candidate['id']}";

                if (isset($seenCandidates[$key])) {
                    $validator->errors()->add("candidates.{$index}.id", 'Canonical candidates must be unique.');
                }

                $seenCandidates[$key] = true;

                if (! in_array($candidate['type'], $allowedTypes, true)) {
                    $validator->errors()->add("candidates.{$index}.type", 'The candidate type is not relevant to this diagnostic field.');
                }

                if (! isset($activeCandidateKeys[$key])) {
                    $validator->errors()->add("candidates.{$index}.id", 'The canonical candidate is unknown or inactive.');
                }
            }
        });

        $validator->validate();
    }

    /** @return list<string> */
    private function allowedCandidateTypes(string $field, ParserDiagnosticType $diagnosticType): array
    {
        return match (true) {
            $field === 'report' && $diagnosticType === ParserDiagnosticType::UnaccountedNumericTokens => [CanonicalEntityType::Species->value],
            str_contains($field, 'species') => [CanonicalEntityType::Species->value],
            str_contains($field, 'boat') => [CanonicalEntityType::Boat->value],
            str_contains($field, 'trip_type') => [CanonicalEntityType::TripType->value],
            $field === 'report' => [CanonicalEntityType::Boat->value],
            default => [],
        };
    }

    /** @return list<string> */
    private function allowedFields(ParserDiagnosticType $type): array
    {
        return match ($type) {
            ParserDiagnosticType::UnknownAlias => ['boat', 'species', 'trip_type'],
            ParserDiagnosticType::FractionalTripConflict => ['trip_type'],
            ParserDiagnosticType::ProseCapturedAsEntity => ['boat', 'landing', 'species'],
            ParserDiagnosticType::ExcessiveNameLength => ['boat', 'landing', 'species', 'trip_type'],
            ParserDiagnosticType::UnaccountedNumericTokens,
            ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet => ['report'],
            ParserDiagnosticType::StructuredSourceFallback => ['parser'],
            ParserDiagnosticType::ExtractedValueSourceSpanMismatch => ['anglers', 'species_counts'],
        };
    }

    /**
     * @param  list<array{type: string, id: int, name: string, aliases: list<string>}>  $candidates
     * @return array<string, true>
     */
    private function activeCandidateKeys(array $candidates): array
    {
        $idsByType = [];

        foreach ($candidates as $candidate) {
            $idsByType[$candidate['type']][] = $candidate['id'];
        }

        $activeKeys = [];

        foreach ($idsByType as $type => $ids) {
            $entityType = CanonicalEntityType::from($type);
            $model = match ($entityType) {
                CanonicalEntityType::Boat => Boat::class,
                CanonicalEntityType::Species => Species::class,
                CanonicalEntityType::TripType => TripType::class,
            };

            foreach ($model::query()->whereKey($ids)->where('is_active', true)->pluck('id') as $id) {
                $activeKeys["{$type}:{$id}"] = true;
            }
        }

        return $activeKeys;
    }
}
