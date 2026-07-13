<?php

namespace App\Enums;

enum ParserDiagnosticReviewStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refused = 'refused';
    case Stale = 'stale';
    case Skipped = 'skipped';

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending => in_array($status, [self::Running, self::Stale, self::Skipped], true),
            self::Running => in_array($status, [self::Succeeded, self::Failed, self::Refused, self::Stale], true),
            self::Failed, self::Succeeded, self::Refused, self::Stale, self::Skipped => $status === self::Pending,
        };
    }
}
