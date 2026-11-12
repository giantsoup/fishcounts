<?php

use App\Http\Controllers\Api\BoatController;
use App\Http\Controllers\Api\ParserErrorController;
use App\Http\Controllers\Api\SpeciesController;
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

    // Boats, species, and trip types (#67 reads and aliases; #70 adds the entity writes here).
    Route::middleware('abilities:read')->group(function (): void {
        Route::get('boats', [BoatController::class, 'index'])->name('boats.index');
        Route::get('boats/{boat}', [BoatController::class, 'show'])->whereNumber('boat')->name('boats.show');
        Route::get('species', [SpeciesController::class, 'index'])->name('species.index');
        Route::get('species/{species}', [SpeciesController::class, 'show'])->whereNumber('species')->name('species.show');
        Route::get('trip-types', [TripTypeController::class, 'index'])->name('trip-types.index');
        Route::get('trip-types/{tripType}', [TripTypeController::class, 'show'])->whereNumber('tripType')->name('trip-types.show');
    });
});
