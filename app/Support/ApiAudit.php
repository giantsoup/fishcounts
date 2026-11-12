<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Audit trail for agent API writes. Every API endpoint that changes data calls
 * ApiAudit::write() once, after the change succeeds. The line carries only the
 * actor, token name, action, and target id: never the token or request headers.
 */
class ApiAudit
{
    /**
     * @param  string  $action  Dotted resource.verb name, such as "parser-error.dismiss".
     */
    public static function write(Request $request, string $action, int|string|null $targetId): void
    {
        Log::info('api.write', [
            'user_id' => $request->user()?->getKey(),
            'token_name' => $request->user()?->currentAccessToken()?->name,
            'action' => $action,
            'target_id' => $targetId,
        ]);
    }
}
