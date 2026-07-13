<?php

namespace App\Enums;

enum ParserReportOverrideStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';
    case Invalidated = 'invalidated';
}
