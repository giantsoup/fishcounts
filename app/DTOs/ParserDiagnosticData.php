<?php

namespace App\DTOs;

use App\Enums\CanonicalEntityType;
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

    /**
     * The parser error type this diagnostic is stored as: unknown aliases split by the entity
     * their field names, with any other field treated as a species.
     */
    public function errorType(): string
    {
        if ($this->type !== ParserDiagnosticType::UnknownAlias) {
            return $this->type->value;
        }

        return (CanonicalEntityType::tryFrom($this->field) ?? CanonicalEntityType::Species)->aliasErrorType();
    }
}
