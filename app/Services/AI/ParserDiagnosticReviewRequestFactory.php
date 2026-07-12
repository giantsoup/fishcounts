<?php

namespace App\Services\AI;

use App\DTOs\CanonicalCandidateData;
use App\DTOs\ParserDiagnosticReviewRequestData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserDiagnosticType;
use App\Models\Boat;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Database\Eloquent\Model;

final class ParserDiagnosticReviewRequestFactory
{
    public function make(RawScrapePayload $payload, ParserError $parserError): ParserDiagnosticReviewRequestData
    {
        $type = $this->diagnosticType($parserError->error_type);

        return new ParserDiagnosticReviewRequestData(
            payloadId: $payload->id,
            payloadHash: $payload->payload_hash,
            diagnosticFingerprint: $parserError->diagnostic_fingerprint,
            diagnosticType: $type,
            field: $parserError->raw_field ?? 'report',
            rawValue: $parserError->raw_value,
            context: $this->safeContext($parserError->context ?? []),
            candidates: $this->candidates($parserError->raw_field ?? 'report'),
        );
    }

    private function diagnosticType(string $errorType): ParserDiagnosticType
    {
        return match ($errorType) {
            'unknown_boat_alias', 'unknown_species_alias', 'unknown_trip_type_alias' => ParserDiagnosticType::UnknownAlias,
            default => ParserDiagnosticType::from($errorType),
        };
    }

    /** @param array<string, mixed> $context */
    private function safeContext(array $context): array
    {
        return array_intersect_key($context, array_flip([
            'source', 'date', 'url', 'parser_version', 'format', 'report_index',
            'source_identifier', 'sanitized_paragraph', 'extracted_fields', 'evidence',
        ]));
    }

    /** @return list<CanonicalCandidateData> */
    private function candidates(string $field): array
    {
        [$type, $model] = match (true) {
            str_contains($field, 'species') => [CanonicalEntityType::Species, Species::class],
            str_contains($field, 'boat'), $field === 'report' => [CanonicalEntityType::Boat, Boat::class],
            str_contains($field, 'trip_type') => [CanonicalEntityType::TripType, TripType::class],
            default => [null, null],
        };

        if ($type === null || $model === null) {
            return [];
        }

        return $model::query()
            ->where('is_active', true)
            ->with(['aliases:id,'.$this->foreignKey($model).',alias'])
            ->orderBy('id')
            ->limit((int) config('fish.ai_review.limits.max_candidates'))
            ->get(['id', 'name'])
            ->map(fn (Model $candidate): CanonicalCandidateData => new CanonicalCandidateData(
                type: $type,
                id: $candidate->getKey(),
                name: $candidate->getAttribute('name'),
                aliases: $candidate->getRelation('aliases')->pluck('alias')->take(20)->values()->all(),
            ))
            ->all();
    }

    /** @param class-string<Model> $model */
    private function foreignKey(string $model): string
    {
        return match ($model) {
            Species::class => 'species_id',
            Boat::class => 'boat_id',
            TripType::class => 'trip_type_id',
        };
    }
}
