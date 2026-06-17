<?php

namespace App\Enums;

enum ScoreLevel: string
{
    case Cold = 'cold';
    case Watch = 'watch';
    case Active = 'active';
    case Hot = 'hot';
    case WideOpen = 'wide_open';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 90 => self::WideOpen,
            $score >= 80 => self::Hot,
            $score >= 70 => self::Active,
            $score >= 60 => self::Watch,
            default => self::Cold,
        };
    }
}
