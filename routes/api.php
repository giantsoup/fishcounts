<?php

use App\Http\Controllers\Api\ParserErrorController;
use App\Http\Controllers\Api\RawPayloadController;
use App\Http\Controllers\Api\RawPayloadReparseController;
use App\Http\Controllers\Api\ReparseRunController;
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
    Route::get('raw-payloads/{rawScrapePayload}/body', [RawPayloadController::class, 'body'])
        ->middleware('abilities:read')
        ->name('raw-payloads.body');
    Route::post('raw-payloads/{rawScrapePayload}/evaluate', [RawPayloadController::class, 'evaluate'])
        ->middleware('abilities:read')
        ->name('raw-payloads.evaluate');

    Route::get('reparse-runs/preview', [ReparseRunController::class, 'preview'])
        ->middleware('abilities:reparse')
        ->name('reparse-runs.preview');
    Route::post('reparse-runs', [ReparseRunController::class, 'store'])
        ->middleware(['abilities:reparse', 'throttle:3,1,parser-reparse-write:'])
        ->name('reparse-runs.store');
    Route::get('reparse-runs/{parserReparseRun}', [ReparseRunController::class, 'show'])
        ->whereNumber('parserReparseRun')
        ->middleware('abilities:read')
        ->name('reparse-runs.show');
    Route::post('reparse-runs/{parserReparseRun}/retry', [ReparseRunController::class, 'retry'])
        ->whereNumber('parserReparseRun')
        ->middleware(['abilities:reparse', 'throttle:3,1,parser-reparse-write:'])
        ->name('reparse-runs.retry');
    Route::post('raw-payloads/{rawScrapePayload}/reparse', RawPayloadReparseController::class)
        ->whereNumber('rawScrapePayload')
        ->middleware('abilities:reparse')
        ->name('raw-payloads.reparse');
});
