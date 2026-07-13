<?php

namespace App\Enums;

enum ParserDiagnosticReviewRunStatus: string
{
    case Preparing = 'preparing';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Preparing, self::Queued, self::Running], true);
    }

    /** @return array<int, string> */
    public static function activeValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            [self::Preparing, self::Queued, self::Running],
        );
    }
}
