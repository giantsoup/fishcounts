<?php

namespace App\DTOs;

use App\Enums\ParserDiagnosticReviewClassification;

final readonly class ParserDiagnosticReviewResultData
{
    /** @param list<ParserDiagnosticCorrectionData> $corrections */
    public function __construct(
        public ParserDiagnosticReviewClassification $classification,
        public float $confidence,
        public string $rationale,
        public array $corrections,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification->value,
            'confidence' => $this->confidence,
            'rationale' => $this->rationale,
            'corrections' => array_map(
                fn (ParserDiagnosticCorrectionData $correction): array => $correction->toArray(),
                $this->corrections,
            ),
        ];
    }
}
