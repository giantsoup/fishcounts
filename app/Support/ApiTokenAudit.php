<?php

namespace App\Support;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Audit trail for agent API token management in the admin UI. Each change writes one line
 * with the acting admin and the token's id, name, owner, abilities, and expiry. It never
 * carries the plaintext, the hash, or the masked hint.
 */
class ApiTokenAudit
{
    /**
     * @param  string  $action  One of created, renamed, rotated, revoked.
     * @param  array<string, mixed>  $extra
     */
    public static function write(Request $request, string $action, PersonalAccessToken $token, array $extra = []): void
    {
        Log::info('api-token.'.$action, [
            'actor_user_id' => $request->user()?->getKey(),
            'actor_email' => $request->user()?->email,
            'token_id' => $token->getKey(),
            'token_name' => $token->name,
            'owner_user_id' => $token->tokenable_id,
            'abilities' => $token->abilities,
            'expires_at' => $token->expires_at?->toIso8601String(),
            ...$extra,
        ]);
    }
}
