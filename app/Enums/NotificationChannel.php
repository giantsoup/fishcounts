<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Email = 'email';
    case Discord = 'discord';
}
