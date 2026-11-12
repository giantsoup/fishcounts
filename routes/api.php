<?php

use App\Http\Controllers\Api\BoatAliasController;
use App\Http\Controllers\Api\BoatController;
use App\Http\Controllers\Api\ParserErrorController;
use App\Http\Controllers\Api\RawPayloadController;
use App\Http\Controllers\Api\RawPayloadReparseController;
use App\Http\Controllers\Api\ReparseRunController;
use App\Http\Controllers\Api\SpeciesAliasController;
use App\Http\Controllers\Api\SpeciesController;
use App\Http\Controllers\Api\TripReportController;
use App\Http\Controllers\Api\TripTypeAliasController;
use App\Http\Controllers\Api\TripTypeController;
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
    Route::get('parser-errors/{parserError}', [ParserErrorController::class, 'show'])
        ->middleware('abilities:read')
        ->name('parser-errors.show');
    Route::patch('parser-errors/{parserError}/dismiss', [ParserErrorController::class, 'dismiss'])
        ->middleware('abilities:errors')
        ->name('parser-errors.dismiss');
    Route::get('trip-reports', [TripReportController::class, 'index'])
        ->middleware('abilities:read')
        ->name('trip-reports.index');
    Route::get('trip-reports/{tripReport}', [TripReportController::class, 'show'])
        ->middleware('abilities:read')
        ->name('trip-reports.show');

    // Boats, species, and trip types (#67 reads and aliases; #70 adds the entity writes here).
    Route::get('boats', [BoatController::class, 'index'])->middleware('abilities:read')->name('boats.index');
    Route::get('boats/{boat}', [BoatController::class, 'show'])->whereNumber('boat')->middleware('abilities:read')->name('boats.show');
    Route::get('boats/{boat}/aliases', [BoatAliasController::class, 'index'])->whereNumber('boat')->middleware('abilities:read')->name('boats.aliases.index');
    Route::post('boats/{boat}/aliases', [BoatAliasController::class, 'store'])->whereNumber('boat')->middleware('abilities:aliases')->name('boats.aliases.store');
    Route::delete('boats/aliases/{boatAlias}', [BoatAliasController::class, 'destroy'])->whereNumber('boatAlias')->middleware('abilities:aliases')->name('boats.aliases.destroy');

    Route::get('species', [SpeciesController::class, 'index'])->middleware('abilities:read')->name('species.index');
    Route::get('species/{species}', [SpeciesController::class, 'show'])->whereNumber('species')->middleware('abilities:read')->name('species.show');
    Route::get('species/{species}/aliases', [SpeciesAliasController::class, 'index'])->whereNumber('species')->middleware('abilities:read')->name('species.aliases.index');
    Route::post('species/{species}/aliases', [SpeciesAliasController::class, 'store'])->whereNumber('species')->middleware('abilities:aliases')->name('species.aliases.store');
    Route::delete('species/aliases/{speciesAlias}', [SpeciesAliasController::class, 'destroy'])->whereNumber('speciesAlias')->middleware('abilities:aliases')->name('species.aliases.destroy');

    Route::get('trip-types', [TripTypeController::class, 'index'])->middleware('abilities:read')->name('trip-types.index');
    Route::get('trip-types/{tripType}', [TripTypeController::class, 'show'])->whereNumber('tripType')->middleware('abilities:read')->name('trip-types.show');
    Route::get('trip-types/{tripType}/aliases', [TripTypeAliasController::class, 'index'])->whereNumber('tripType')->middleware('abilities:read')->name('trip-types.aliases.index');
    Route::post('trip-types/{tripType}/aliases', [TripTypeAliasController::class, 'store'])->whereNumber('tripType')->middleware('abilities:aliases')->name('trip-types.aliases.store');
    Route::delete('trip-types/aliases/{tripTypeAlias}', [TripTypeAliasController::class, 'destroy'])->whereNumber('tripTypeAlias')->middleware('abilities:aliases')->name('trip-types.aliases.destroy');
});
