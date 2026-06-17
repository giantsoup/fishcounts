<?php

namespace App\Enums;

enum SourceType: string
{
    case Aggregator = 'aggregator';
    case Landing = 'landing';
    case ReportFeed = 'report_feed';
    case Fallback = 'fallback';
}
