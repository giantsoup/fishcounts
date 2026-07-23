<?php

namespace App\Enums;

enum AiParserAttemptCostBasis: string
{
    case None = 'none';
    case Metered = 'metered';
    case EstimatedConservative = 'estimated_conservative';
    case Unknown = 'unknown';
}
