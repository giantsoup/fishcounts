<?php

namespace App\Enums;

enum ScrapeRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Partial = 'partial';
    case Cancelled = 'cancelled';
    case Unavailable = 'unavailable';
}
