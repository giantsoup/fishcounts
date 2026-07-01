<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

class EnvironmentalFetchResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $url,
        public readonly ?int $statusCode,
        public readonly ?string $contentType,
        public readonly string $body,
        public readonly CarbonImmutable $fetchedAt,
        public readonly array $metadata = [],
    ) {}
}
