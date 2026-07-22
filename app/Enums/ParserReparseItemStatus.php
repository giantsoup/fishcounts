<?php

namespace App\Enums;

enum ParserReparseItemStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }
}
