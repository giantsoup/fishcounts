<?php

namespace App\Enums;

enum HistoricalAiReviewRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case Completed = 'completed';
    case Failed = 'failed';
}
