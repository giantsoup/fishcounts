<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless counterpart of EnsureUserIsActive for token-authenticated API routes:
 * rejects a valid token whose user has been deactivated, without touching a session.
 */
class EnsureApiUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->is_active) {
            return response()->json(['message' => 'This account is inactive.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
