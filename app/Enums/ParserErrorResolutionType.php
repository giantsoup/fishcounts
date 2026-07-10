<?php

namespace App\Enums;

enum ParserErrorResolutionType: string
{
    case Alias = 'alias';
    case Dismissed = 'dismissed';
}
