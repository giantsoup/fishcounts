<?php

namespace App\Services\Parsing;

final class RegulationResearchSchema
{
    /** @return array<string, mixed> */
    public function format(): array
    {
        $properties = [
            'daily' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 100],
            'max_days' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 3],
            'effective_from' => ['type' => ['string', 'null']],
            'effective_until' => ['type' => ['string', 'null']],
            'section' => ['type' => ['string', 'null']],
            'applicability' => ['type' => 'string', 'enum' => ['supported', 'unresolved', 'proposed', 'prohibited']],
            'restrictions' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
            'citations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['url', 'quote'],
                'properties' => ['url' => ['type' => 'string', 'maxLength' => 2000], 'quote' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 3000]],
            ]],
        ];

        return ['type' => 'json_schema', 'name' => 'regulation_candidate', 'strict' => true, 'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)]];
    }
}
