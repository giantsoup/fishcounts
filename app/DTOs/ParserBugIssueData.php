<?php

namespace App\DTOs;

final readonly class ParserBugIssueData
{
    /**
     * @param  list<string>  $requiredLabels
     * @param  list<string>  $optionalLabels
     * @param  list<string>  $assignees
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $requiredLabels,
        public array $optionalLabels,
        public array $assignees,
    ) {}
}
