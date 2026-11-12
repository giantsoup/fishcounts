<?php

use App\Http\Controllers\Api\ParserErrorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->name('api.')->group(function (): void {
    Route::get('parser-errors', [ParserErrorController::class, 'index'])->name('parser-errors.index');
});
