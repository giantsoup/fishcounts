<?php

namespace App\Enums;

enum QueueParserDiagnosticReviewResult
{
    case ExistingReview;
    case AlreadyQueued;
    case ReparseQueued;
    case ReviewQueued;
}
