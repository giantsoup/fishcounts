<?php

namespace App\DTOs;

use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionField;
use App\Enums\ParserCorrectionOperation;

final readonly class ParserDiagnosticCorrectionData
{
    public function __construct(
        public ParserCorrectionOperation $operation,
        public int $reportIndex,
        public ParserCorrectionField $field,
        public ?CanonicalEntityType $canonicalType,
        public ?int $canonicalId,
        public ?int $value,
        public ?int $retainedCount,
        public ?int $releasedCount,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'report_index' => $this->reportIndex,
            'field' => $this->field->value,
            'canonical_type' => $this->canonicalType?->value,
            'canonical_id' => $this->canonicalId,
            'value' => $this->value,
            'retained_count' => $this->retainedCount,
            'released_count' => $this->releasedCount,
        ];
    }
}
