<?php

namespace App\Enums;

enum ParserReparseRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
