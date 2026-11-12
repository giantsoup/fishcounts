<?php

namespace App\Actions\Parsing;

use App\Enums\ParserErrorResolutionType;
use App\Models\ParserError;

class DismissParserError
{
    /**
     * Dismiss an unresolved parser error without creating an alias.
     *
     * Returns false when the error was already resolved, leaving its resolver untouched.
     */
    public function handle(ParserError $parserError, ?int $resolvedByUserId): bool
    {
        return ParserError::query()
            ->whereKey($parserError->getKey())
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $resolvedByUserId,
                'resolution_type' => ParserErrorResolutionType::Dismissed->value,
            ]) === 1;
    }
}
