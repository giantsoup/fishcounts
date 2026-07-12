<?php

namespace App\DTOs;

use App\Enums\ParserDiagnosticType;

final readonly class ParserDiagnosticReviewRequestData
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<CanonicalCandidateData>  $candidates
     */
    public function __construct(
        public int $payloadId,
        public string $payloadHash,
        public string $diagnosticFingerprint,
        public ParserDiagnosticType $diagnosticType,
        public string $field,
        public ?string $rawValue,
        public array $context,
        public array $candidates,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'payload_id' => $this->payloadId,
            'payload_hash' => $this->payloadHash,
            'diagnostic_fingerprint' => $this->diagnosticFingerprint,
            'diagnostic_type' => $this->diagnosticType->value,
            'field' => $this->field,
            'raw_value' => $this->rawValue,
            'context' => $this->context,
            'candidates' => array_map(
                fn (CanonicalCandidateData $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
        ];
    }
}
