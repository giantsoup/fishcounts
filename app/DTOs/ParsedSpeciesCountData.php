<?php

namespace App\DTOs;

final readonly class ParsedSpeciesCountData
{
    public function __construct(
        public string $speciesName,
        public int $count,
        public int $releasedCount = 0,
        public ?string $rawText = null,
        public ?int $canonicalSpeciesId = null,
    ) {}
}
