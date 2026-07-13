<?php

namespace App\Enums;

enum ParserErrorResolutionType: string
{
    case Alias = 'alias';
    case AiAssistedAlias = 'ai_assisted_alias';
    case Dismissed = 'dismissed';
}
