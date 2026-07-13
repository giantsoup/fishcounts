<?php

namespace App\DTOs;

final readonly class ParserBugIssueCandidateData
{
    /** @param list<string> $labels */
    public function __construct(
        public string $signature,
        public string $sourceSlug,
        public string $title,
        public string $body,
        public array $labels,
    ) {}
}
