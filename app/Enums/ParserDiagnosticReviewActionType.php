<?php

namespace App\Enums;

enum ParserDiagnosticReviewActionType: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Dismissed = 'dismissed';
    case Retried = 'retried';
    case LeftOpen = 'left_open';
    case AutomaticallyAccepted = 'automatically_accepted';
    case AutomationReversed = 'automation_reversed';
}
