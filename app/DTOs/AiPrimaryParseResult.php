<?php

namespace App\DTOs;

final readonly class AiPrimaryParseResult
{
    /** @param array<string, mixed> $comparison */
    public function __construct(
        public ParsedFishCountCollection $parsed,
        public array $comparison,
        public AiParserProviderResponseData $providerResponse,
        public string $sanitizedInputHash,
        public string $catalogVersion,
        public int $costMicros,
    ) {}
}
