<?php

namespace App\DTOs;

use App\Enums\ParserDiagnosticType;

final readonly class ParserDiagnosticFindingData
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public ParserDiagnosticType $type,
        public string $field,
        public ?string $rawValue,
        public string $message,
        public array $evidence,
    ) {}
}
