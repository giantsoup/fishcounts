<?php

namespace App\Services\AI;

use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionField;
use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserDiagnosticReviewClassification;

final class ParserDiagnosticReviewSchema
{
    /** @return array<string, mixed> */
    public function format(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'parser_diagnostic_review',
            'strict' => true,
            'schema' => $this->schema(),
        ];
    }

    /** @return array<string, mixed> */
    public function batchFormat(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'parser_diagnostic_review_batch',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'diagnostic_fingerprint' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                                ...$this->schema()['properties'],
                            ],
                            'required' => ['diagnostic_fingerprint', ...$this->schema()['required']],
                        ],
                    ],
                ],
                'required' => ['results'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'classification' => [
                    'type' => 'string',
                    'enum' => array_column(ParserDiagnosticReviewClassification::cases(), 'value'),
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'rationale' => [
                    'type' => 'string',
                    'maxLength' => (int) config('fish.ai_review.limits.max_rationale_length'),
                ],
                'corrections' => [
                    'type' => 'array',
                    'maxItems' => (int) config('fish.ai_review.limits.max_corrections'),
                    'items' => $this->correctionSchema(),
                ],
            ],
            'required' => ['classification', 'confidence', 'rationale', 'corrections'],
        ];
    }

    /** @return array<string, mixed> */
    private function correctionSchema(): array
    {
        $entityCorrections = [];

        foreach ([ParserCorrectionOperation::MapAlias, ParserCorrectionOperation::ReplaceEntity] as $operation) {
            foreach ([CanonicalEntityType::Boat, CanonicalEntityType::Species, CanonicalEntityType::TripType] as $canonicalType) {
                $entityCorrections[] = $this->correctionVariant(
                    operation: $operation,
                    field: match ($canonicalType) {
                        CanonicalEntityType::Boat => ParserCorrectionField::Boat,
                        CanonicalEntityType::Species => ParserCorrectionField::Species,
                        CanonicalEntityType::TripType => ParserCorrectionField::TripType,
                    },
                    canonicalType: $canonicalType,
                    canonicalIdSchema: ['type' => 'integer', 'minimum' => 1],
                    valueSchema: ['type' => 'null'],
                    retainedCountSchema: ['type' => 'null'],
                    releasedCountSchema: ['type' => 'null'],
                );
            }
        }

        return [
            'anyOf' => [
                ...$entityCorrections,
                $this->correctionVariant(
                    operation: ParserCorrectionOperation::SetAnglerCount,
                    field: ParserCorrectionField::Anglers,
                    canonicalType: null,
                    canonicalIdSchema: ['type' => 'null'],
                    valueSchema: ['type' => 'integer', 'minimum' => 0],
                    retainedCountSchema: ['type' => 'null'],
                    releasedCountSchema: ['type' => 'null'],
                ),
                $this->correctionVariant(
                    operation: ParserCorrectionOperation::SetSpeciesCount,
                    field: ParserCorrectionField::SpeciesCount,
                    canonicalType: CanonicalEntityType::Species,
                    canonicalIdSchema: ['type' => 'integer', 'minimum' => 1],
                    valueSchema: ['type' => 'null'],
                    retainedCountSchema: ['type' => 'integer', 'minimum' => 0],
                    releasedCountSchema: ['type' => 'integer', 'minimum' => 0],
                ),
                $this->correctionVariant(
                    operation: ParserCorrectionOperation::RemoveSpeciesCount,
                    field: ParserCorrectionField::SpeciesCount,
                    canonicalType: CanonicalEntityType::Species,
                    canonicalIdSchema: ['type' => 'integer', 'minimum' => 1],
                    valueSchema: ['type' => 'null'],
                    retainedCountSchema: ['type' => 'null'],
                    releasedCountSchema: ['type' => 'null'],
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $canonicalIdSchema
     * @param  array<string, mixed>  $valueSchema
     * @param  array<string, mixed>  $retainedCountSchema
     * @param  array<string, mixed>  $releasedCountSchema
     * @return array<string, mixed>
     */
    private function correctionVariant(
        ParserCorrectionOperation $operation,
        ParserCorrectionField $field,
        ?CanonicalEntityType $canonicalType,
        array $canonicalIdSchema,
        array $valueSchema,
        array $retainedCountSchema,
        array $releasedCountSchema,
    ): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => [$operation->value],
                ],
                'report_index' => ['type' => 'integer', 'minimum' => 0],
                'field' => [
                    'type' => 'string',
                    'enum' => [$field->value],
                ],
                'canonical_type' => $canonicalType === null
                    ? ['type' => 'null']
                    : ['type' => 'string', 'enum' => [$canonicalType->value]],
                'canonical_id' => $canonicalIdSchema,
                'value' => $valueSchema,
                'retained_count' => $retainedCountSchema,
                'released_count' => $releasedCountSchema,
            ],
            'required' => $this->correctionProperties(),
        ];
    }

    /** @return list<string> */
    private function correctionProperties(): array
    {
        return [
            'operation',
            'report_index',
            'field',
            'canonical_type',
            'canonical_id',
            'value',
            'retained_count',
            'released_count',
        ];
    }
}
