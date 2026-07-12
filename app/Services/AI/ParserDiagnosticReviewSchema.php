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
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => array_column(ParserCorrectionOperation::cases(), 'value'),
                ],
                'report_index' => ['type' => 'integer', 'minimum' => 0],
                'field' => [
                    'type' => 'string',
                    'enum' => array_column(ParserCorrectionField::cases(), 'value'),
                ],
                'canonical_type' => [
                    'type' => ['string', 'null'],
                    'enum' => [...array_column(CanonicalEntityType::cases(), 'value'), null],
                ],
                'canonical_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'value' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'retained_count' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'released_count' => ['type' => ['integer', 'null'], 'minimum' => 0],
            ],
            'required' => [
                'operation',
                'report_index',
                'field',
                'canonical_type',
                'canonical_id',
                'value',
                'retained_count',
                'released_count',
            ],
        ];
    }
}
