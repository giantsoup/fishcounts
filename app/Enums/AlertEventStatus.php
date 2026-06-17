<?php

namespace App\Enums;

enum AlertEventStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Partial = 'partial';
    case Failed = 'failed';
}
