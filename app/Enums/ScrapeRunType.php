<?php

namespace App\Enums;

enum ScrapeRunType: string
{
    case Daily = 'daily';
    case Backfill = 'backfill';
    case Manual = 'manual';
    case Reparse = 'reparse';
}
