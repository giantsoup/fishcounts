<?php

namespace App\Enums;

enum ParserBugReportStatus: string
{
    case Preview = 'preview';
    case Pending = 'pending';
    case Creating = 'creating';
    case Open = 'open';
    case Closed = 'closed';
    case Failed = 'failed';
    case Invalidated = 'invalidated';
}
