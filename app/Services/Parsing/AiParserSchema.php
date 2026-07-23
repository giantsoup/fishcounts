<?php

namespace App\Services\Parsing;

final class AiParserSchema
{
    /** @return array<string, mixed> */
    public function format(): array
    {
        $nullableString = ['type' => ['string', 'null'], 'maxLength' => 255];
        $nullableInteger = ['type' => ['integer', 'null']];
        $reportEvidenceSpans = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 4,
            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
        ];
        $speciesEvidenceSpans = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 4,
            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        ];

        return [
            'type' => 'json_schema',
            'name' => 'fish_count_reports',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['reports'],
                'properties' => [
                    'reports' => [
                        'type' => 'array',
                        'maxItems' => (int) config('fish.ai_parsing.limits.max_reports'),
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => [
                                'source_item_id', 'evidence_spans', 'raw_boat_name', 'canonical_boat_id',
                                'raw_landing_name', 'canonical_landing_id', 'raw_trip_type',
                                'canonical_trip_type_id', 'anglers', 'raw_fish_count_text', 'species_counts',
                            ],
                            'properties' => [
                                'source_item_id' => [
                                    'type' => 'string',
                                    'minLength' => 10,
                                    'maxLength' => 200,
                                    'pattern' => '^block:\d{4}(?:#\d+)?$',
                                ],
                                'evidence_spans' => $reportEvidenceSpans,
                                'raw_boat_name' => $nullableString,
                                'canonical_boat_id' => $nullableInteger,
                                'raw_landing_name' => $nullableString,
                                'canonical_landing_id' => $nullableInteger,
                                'raw_trip_type' => $nullableString,
                                'canonical_trip_type_id' => $nullableInteger,
                                'anglers' => $nullableInteger,
                                'raw_fish_count_text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8000],
                                'species_counts' => [
                                    'type' => 'array',
                                    'maxItems' => (int) config('fish.ai_parsing.limits.max_species_per_report'),
                                    'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'required' => ['raw_species_name', 'canonical_species_id', 'retained_count', 'released_count', 'evidence_spans'],
                                        'properties' => [
                                            'raw_species_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                                            'canonical_species_id' => $nullableInteger,
                                            'retained_count' => ['type' => 'integer', 'minimum' => 0],
                                            'released_count' => ['type' => 'integer', 'minimum' => 0],
                                            'evidence_spans' => $speciesEvidenceSpans,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
