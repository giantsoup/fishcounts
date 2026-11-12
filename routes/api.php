<?php

use App\Enums\ApiAbility;
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
| declares the one token ability it needs with ->middleware(ApiAbility::<Case>->middleware()).
|
| Controllers live in App\Http\Controllers\Api, form requests in
| App\Http\Requests\Api (read input with validated() only), and responses
| go out through App\Http\Resources. Writes call App\Support\ApiAudit::write().
*/
Route::middleware('agent-api')->name('api.')->group(function (): void {
    Route::get('parser-errors', [ParserErrorController::class, 'index'])
        ->middleware(ApiAbility::Read->middleware())
        ->name('parser-errors.index');
    Route::get('raw-payloads/{rawScrapePayload}', [RawPayloadController::class, 'show'])
        ->middleware(ApiAbility::Read->middleware())
        ->name('raw-payloads.show');
    Route::get('raw-payloads/{rawScrapePayload}/body', [RawPayloadController::class, 'body'])
        ->middleware(ApiAbility::Read->middleware())
        ->name('raw-payloads.body');
    Route::post('raw-payloads/{rawScrapePayload}/evaluate', [RawPayloadController::class, 'evaluate'])
        ->middleware(ApiAbility::Read->middleware())
        ->name('raw-payloads.evaluate');
    Route::get('reparse-runs/preview', [ReparseRunController::class, 'preview'])
        ->middleware(ApiAbility::Reparse->middleware())
        ->name('reparse-runs.preview');
    Route::post('reparse-runs', [ReparseRunController::class, 'store'])
        ->middleware([ApiAbility::Reparse->middleware(), 'throttle:3,1,parser-reparse-write:'])
        ->name('reparse-runs.store');
    Route::get('reparse-runs/{parserReparseRun}', [ReparseRunController::class, 'show'])
        ->whereNumber('parserReparseRun')
        ->middleware(ApiAbility::Read->middleware())
        ->name('reparse-runs.show');
    Route::post('reparse-runs/{parserReparseRun}/retry', [ReparseRunController::class, 'retry'])
        ->whereNumber('parserReparseRun')
        ->middleware([ApiAbility::Reparse->middleware(), 'throttle:3,1,parser-reparse-write:'])
        ->name('reparse-runs.retry');
    Route::post('raw-payloads/{rawScrapePayload}/reparse', RawPayloadReparseController::class)
        ->whereNumber('rawScrapePayload')
        ->middleware(ApiAbility::Reparse->middleware())
        ->name('raw-payloads.reparse');
    Route::get('parser-errors/{parserError}', [ParserErrorController::class, 'show'])
        ->middleware(ApiAbility::Read->middleware())
        ->name('parser-errors.show');
    Route::patch('parser-errors/{parserError}/dismiss', [ParserErrorController::class, 'dismiss'])
        ->middleware(ApiAbility::Errors->middleware())
        ->name('parser-errors.dismiss');
    Route::get('trip-reports', [TripReportController::class, 'index'])
        ->middleware(ApiAbility::Read->middleware())
        ->name('trip-reports.index');
    Route::get('trip-reports/{tripReport}', [TripReportController::class, 'show'])
        ->middleware(ApiAbility::Read->middleware())
        ->name('trip-reports.show');

    Route::get('boats', [BoatController::class, 'index'])->middleware(ApiAbility::Read->middleware())->name('boats.index');
    Route::post('boats', [BoatController::class, 'store'])->middleware(ApiAbility::Entities->middleware())->name('boats.store');
    Route::get('boats/{boat}', [BoatController::class, 'show'])->whereNumber('boat')->middleware(ApiAbility::Read->middleware())->name('boats.show');
    Route::patch('boats/{boat}', [BoatController::class, 'update'])->whereNumber('boat')->middleware(ApiAbility::Entities->middleware())->name('boats.update');
    Route::delete('boats/{boat}', [BoatController::class, 'destroy'])->whereNumber('boat')->middleware(ApiAbility::Entities->middleware())->name('boats.destroy');
    Route::post('boats/{boat}/merge', [BoatController::class, 'merge'])->whereNumber('boat')->middleware(ApiAbility::Entities->middleware())->name('boats.merge');
    Route::get('boats/{boat}/aliases', [BoatAliasController::class, 'index'])->whereNumber('boat')->middleware(ApiAbility::Read->middleware())->name('boats.aliases.index');
    Route::post('boats/{boat}/aliases', [BoatAliasController::class, 'store'])->whereNumber('boat')->middleware(ApiAbility::Aliases->middleware())->name('boats.aliases.store');
    Route::delete('boats/aliases/{boatAlias}', [BoatAliasController::class, 'destroy'])->whereNumber('boatAlias')->middleware(ApiAbility::Aliases->middleware())->name('boats.aliases.destroy');

    Route::get('species', [SpeciesController::class, 'index'])->middleware(ApiAbility::Read->middleware())->name('species.index');
    Route::post('species', [SpeciesController::class, 'store'])->middleware(ApiAbility::Entities->middleware())->name('species.store');
    Route::get('species/{species}', [SpeciesController::class, 'show'])->whereNumber('species')->middleware(ApiAbility::Read->middleware())->name('species.show');
    Route::patch('species/{species}', [SpeciesController::class, 'update'])->whereNumber('species')->middleware(ApiAbility::Entities->middleware())->name('species.update');
    Route::delete('species/{species}', [SpeciesController::class, 'destroy'])->whereNumber('species')->middleware(ApiAbility::Entities->middleware())->name('species.destroy');
    Route::get('species/{species}/aliases', [SpeciesAliasController::class, 'index'])->whereNumber('species')->middleware(ApiAbility::Read->middleware())->name('species.aliases.index');
    Route::post('species/{species}/aliases', [SpeciesAliasController::class, 'store'])->whereNumber('species')->middleware(ApiAbility::Aliases->middleware())->name('species.aliases.store');
    Route::delete('species/aliases/{speciesAlias}', [SpeciesAliasController::class, 'destroy'])->whereNumber('speciesAlias')->middleware(ApiAbility::Aliases->middleware())->name('species.aliases.destroy');

    Route::get('trip-types', [TripTypeController::class, 'index'])->middleware(ApiAbility::Read->middleware())->name('trip-types.index');
    Route::post('trip-types', [TripTypeController::class, 'store'])->middleware(ApiAbility::Entities->middleware())->name('trip-types.store');
    Route::get('trip-types/{tripType}', [TripTypeController::class, 'show'])->whereNumber('tripType')->middleware(ApiAbility::Read->middleware())->name('trip-types.show');
    Route::patch('trip-types/{tripType}', [TripTypeController::class, 'update'])->whereNumber('tripType')->middleware(ApiAbility::Entities->middleware())->name('trip-types.update');
    Route::delete('trip-types/{tripType}', [TripTypeController::class, 'destroy'])->whereNumber('tripType')->middleware(ApiAbility::Entities->middleware())->name('trip-types.destroy');
    Route::get('trip-types/{tripType}/aliases', [TripTypeAliasController::class, 'index'])->whereNumber('tripType')->middleware(ApiAbility::Read->middleware())->name('trip-types.aliases.index');
    Route::post('trip-types/{tripType}/aliases', [TripTypeAliasController::class, 'store'])->whereNumber('tripType')->middleware(ApiAbility::Aliases->middleware())->name('trip-types.aliases.store');
    Route::delete('trip-types/aliases/{tripTypeAlias}', [TripTypeAliasController::class, 'destroy'])->whereNumber('tripTypeAlias')->middleware(ApiAbility::Aliases->middleware())->name('trip-types.aliases.destroy');
});
