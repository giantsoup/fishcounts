<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BackfillController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\NotificationDeliveryController;
use App\Http\Controllers\Admin\ParserErrorController;
use App\Http\Controllers\Admin\RawPayloadController;
use App\Http\Controllers\Admin\ScrapeRunController;
use App\Http\Controllers\Admin\ScrapeSourceController;
use App\Http\Controllers\Admin\SpeciesAliasController;
use App\Http\Controllers\Admin\TripTypeAliasController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AlertHistoryController;
use App\Http\Controllers\AlertRuleController;
use App\Http\Controllers\CountsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScoresController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/healthz', function () {
    DB::select('select 1');

    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toISOString(),
    ]);
})->name('healthz');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/counts', CountsController::class)->name('counts.index');
    Route::get('/scores', ScoresController::class)->name('scores.index');
    Route::resource('alert-rules', AlertRuleController::class)->except(['show']);
    Route::get('/notification-settings', [NotificationSettingsController::class, 'edit'])->name('notification-settings.edit');
    Route::put('/notification-settings', [NotificationSettingsController::class, 'update'])->name('notification-settings.update');
    Route::post('/notification-settings/{notificationDestination}/test', [NotificationSettingsController::class, 'test'])->name('notification-settings.test');
    Route::get('/alerts', AlertHistoryController::class)->name('alerts.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::resource('sources', ScrapeSourceController::class)->only(['index', 'update']);
        Route::get('backfills/poll', [BackfillController::class, 'poll'])->name('backfills.poll');
        Route::resource('backfills', BackfillController::class)->only(['index', 'create', 'store']);
        Route::post('backfills/{backfillRun}/pause', [BackfillController::class, 'pause'])->name('backfills.pause');
        Route::post('backfills/{backfillRun}/resume', [BackfillController::class, 'resume'])->name('backfills.resume');
        Route::post('backfills/{backfillRun}/retry-failed', [BackfillController::class, 'retryFailed'])->name('backfills.retry-failed');
        Route::post('backfills/{backfillRun}/cancel', [BackfillController::class, 'cancel'])->name('backfills.cancel');
        Route::resource('scrape-runs', ScrapeRunController::class)->only(['index', 'show']);
        Route::get('raw-payloads/{rawScrapePayload}', RawPayloadController::class)->name('raw-payloads.show');
        Route::post('raw-payloads/{rawScrapePayload}/reparse', [RawPayloadController::class, 'reparse'])->name('raw-payloads.reparse');
        Route::get('parser-errors', ParserErrorController::class)->name('parser-errors.index');
        Route::get('species', [SpeciesAliasController::class, 'index'])->name('species-aliases.index');
        Route::post('species/aliases', [SpeciesAliasController::class, 'store'])->name('species-aliases.store');
        Route::get('trip-types', [TripTypeAliasController::class, 'index'])->name('trip-type-aliases.index');
        Route::post('trip-types/aliases', [TripTypeAliasController::class, 'store'])->name('trip-type-aliases.store');
        Route::get('notification-logs', NotificationDeliveryController::class)->name('notification-logs.index');
        Route::get('failed-jobs', FailedJobController::class)->name('failed-jobs.index');
    });
});

require __DIR__.'/auth.php';
