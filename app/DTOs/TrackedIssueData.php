<?php

namespace App\DTOs;

final readonly class TrackedIssueData
{
    public function __construct(
        public int $number,
        public string $url,
        public string $state,
    ) {}
}
