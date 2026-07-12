<?php

namespace App\DTOs;

use App\Enums\ParserDiagnosticType;

final readonly class ParserDiagnosticData
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public ParserDiagnosticType $type,
        public string $field,
        public ?string $rawValue,
        public string $message,
        public array $context,
        public string $reportFingerprint,
        public string $diagnosticFingerprint,
    ) {}
}
