<?php

namespace App\Enums;

enum HistoricalAiReviewRunItemStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
