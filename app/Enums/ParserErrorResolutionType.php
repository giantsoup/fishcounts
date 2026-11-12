<?php

namespace App\Enums;

enum ParserErrorResolutionType: string
{
    case Alias = 'alias';
    case AiAssistedAlias = 'ai_assisted_alias';
    case Dismissed = 'dismissed';

    /**
     * An alias added by the external agent through the API (ADR 0001).
     */
    case AgentAlias = 'agent_alias';
}
