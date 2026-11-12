<?php

use App\Http\Middleware\EnsureApiUserIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureUserIsActive::class]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'abilities' => CheckAbilities::class,
        ]);

        // Agent API stack: bearer token, per-token rate limit, active account, admin. Route-model
        // binding runs last so a rejected caller gets 401/403, never a 404 that confirms a record exists.
        $middleware->api(remove: [SubstituteBindings::class]);
        $middleware->group('agent-api', [
            'auth:sanctum',
            'throttle:agent-api',
            EnsureApiUserIsActive::class,
            'admin',
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
