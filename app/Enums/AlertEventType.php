<?php

namespace App\Enums;

enum AlertEventType: string
{
    case ThresholdCrossed = 'threshold_crossed';
    case WeeklyDigest = 'weekly_digest';
}
