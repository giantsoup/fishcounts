<?php

namespace App\Enums;

enum AiBudgetReservationStatus: string
{
    case Reserved = 'reserved';
    case Settled = 'settled';
    case Released = 'released';
}
