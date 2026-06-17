<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class FetchResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $url,
        public ?int $statusCode,
        public ?string $contentType,
        public string $body,
        public CarbonImmutable $fetchedAt,
        public array $metadata = [],
    ) {}
}
