<?php

use App\Http\Controllers\Api\ParserErrorController;
use App\Http\Controllers\Api\RawPayloadController;
use Illuminate\Support\Facades\Route;

/*
| Agent API. Every route goes inside the agent-api group (bootstrap/app.php):
| bearer token, per-token rate limit, active account, admin. Each route then
| declares the one token ability it needs with ->middleware('abilities:<ability>'),
| where the ability is read, aliases, entities, reparse, or errors.
|
| Controllers live in App\Http\Controllers\Api, form requests in
| App\Http\Requests\Api (read input with validated() only), and responses
| go out through App\Http\Resources. Writes call App\Support\ApiAudit::write().
*/
Route::middleware('agent-api')->name('api.')->group(function (): void {
    Route::get('parser-errors', [ParserErrorController::class, 'index'])
        ->middleware('abilities:read')
        ->name('parser-errors.index');

    Route::get('raw-payloads/{rawScrapePayload}', [RawPayloadController::class, 'show'])
        ->middleware('abilities:read')
        ->name('raw-payloads.show');
});
