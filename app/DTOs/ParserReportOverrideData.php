<?php

namespace App\DTOs;

use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionField;
use App\Enums\ParserCorrectionOperation;

final readonly class ParserReportOverrideData
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
        public ?string $matchValue,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            operation: ParserCorrectionOperation::from($data['operation']),
            reportIndex: $data['report_index'],
            field: ParserCorrectionField::from($data['field']),
            canonicalType: $data['canonical_type'] === null ? null : CanonicalEntityType::from($data['canonical_type']),
            canonicalId: $data['canonical_id'],
            value: $data['value'],
            retainedCount: $data['retained_count'],
            releasedCount: $data['released_count'],
            matchValue: $data['match_value'],
        );
    }

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
            'match_value' => $this->matchValue,
        ];
    }
}
