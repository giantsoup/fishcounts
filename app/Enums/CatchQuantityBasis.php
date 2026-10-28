<?php

namespace App\Enums;

enum CatchQuantityBasis: string
{
    case Explicit = 'explicit';
    case Corroborated = 'corroborated';
    case LimitsEstimated = 'limits_estimated';
    case LimitsUnquantified = 'limits_unquantified';
    case LegacyUnclassified = 'legacy_unclassified';
    case Mixed = 'mixed';
}
