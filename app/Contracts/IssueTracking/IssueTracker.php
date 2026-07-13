<?php

namespace App\Contracts\IssueTracking;

use App\DTOs\ParserBugIssueData;
use App\DTOs\TrackedIssueData;

interface IssueTracker
{
    public function create(ParserBugIssueData $issue): TrackedIssueData;

    public function get(int $issueNumber): TrackedIssueData;
}
