<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class RawPayloadData
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $sourceKey,
        public CarbonImmutable $targetDate,
        public string $url,
        public string $body,
        public array $metadata = [],
    ) {}
}
