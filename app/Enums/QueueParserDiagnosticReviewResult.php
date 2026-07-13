<?php

namespace App\Enums;

enum QueueParserDiagnosticReviewResult
{
    case ExistingReview;
    case ReparseQueued;
    case ReviewQueued;
}
