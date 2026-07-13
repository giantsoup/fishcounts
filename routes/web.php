<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BackfillController;
use App\Http\Controllers\Admin\BoatController;
use App\Http\Controllers\Admin\EnvironmentalBackfillController;
use App\Http\Controllers\Admin\EnvironmentalConditionController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\NotificationDeliveryController;
use App\Http\Controllers\Admin\ParserBugReportController;
use App\Http\Controllers\Admin\ParserDiagnosticReviewController;
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
use App\Http\Controllers\ScoreHotBiteEmailController;
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
    Route::post('/scores/{scoreResult}/hot-bite-email', ScoreHotBiteEmailController::class)
        ->middleware('throttle:6,1')
        ->name('scores.hot-bite-email');
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
        Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordResetLink'])->name('users.password-reset');
        Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::resource('sources', ScrapeSourceController::class)->only(['index', 'update']);
        Route::resource('boats', BoatController::class)->only(['index', 'store', 'update']);
        Route::post('boats/aliases', [BoatController::class, 'storeAlias'])->name('boat-aliases.store');
        Route::get('backfills/poll', [BackfillController::class, 'poll'])->name('backfills.poll');
        Route::resource('backfills', BackfillController::class)->only(['index', 'create', 'store', 'show'])->parameters(['backfills' => 'backfillRun']);
        Route::get('backfills/{backfillRun}/reparse-poll', [BackfillController::class, 'pollReparse'])->name('backfills.reparse-poll');
        Route::post('backfills/{backfillRun}/reparse', [BackfillController::class, 'reparse'])->name('backfills.reparse');
        Route::post('backfills/{backfillRun}/pause', [BackfillController::class, 'pause'])->name('backfills.pause');
        Route::post('backfills/{backfillRun}/resume', [BackfillController::class, 'resume'])->name('backfills.resume');
        Route::post('backfills/{backfillRun}/retry-failed', [BackfillController::class, 'retryFailed'])->name('backfills.retry-failed');
        Route::post('backfills/{backfillRun}/cancel', [BackfillController::class, 'cancel'])->name('backfills.cancel');
        Route::resource('scrape-runs', ScrapeRunController::class)->only(['index', 'show']);
        Route::get('conditions', EnvironmentalConditionController::class)->name('conditions.index');
        Route::post('conditions/backfills', EnvironmentalBackfillController::class)
            ->middleware('throttle:3,1')
            ->name('conditions.backfills.store');
        Route::get('raw-payloads/{rawScrapePayload}', RawPayloadController::class)->name('raw-payloads.show');
        Route::post('raw-payloads/{rawScrapePayload}/reparse', [RawPayloadController::class, 'reparse'])->name('raw-payloads.reparse');
        Route::get('parser-errors', ParserErrorController::class)->name('parser-errors.index');
        Route::patch('parser-errors/{parserError}/dismiss', [ParserErrorController::class, 'dismiss'])->name('parser-errors.dismiss');
        Route::prefix('parser-errors/{parserError}/reviews/{review}')->name('parser-errors.reviews.')->group(function (): void {
            Route::post('accept', [ParserDiagnosticReviewController::class, 'accept'])->name('accept');
            Route::post('reject', [ParserDiagnosticReviewController::class, 'reject'])->name('reject');
            Route::post('dismiss', [ParserDiagnosticReviewController::class, 'dismiss'])->name('dismiss');
            Route::post('retry', [ParserDiagnosticReviewController::class, 'retry'])->name('retry');
            Route::post('leave-open', [ParserDiagnosticReviewController::class, 'leaveOpen'])->name('leave-open');
            Route::post('automatic-actions/{automaticAction}/reverse', [ParserDiagnosticReviewController::class, 'reverseAutomation'])
                ->name('reverse-automation');
            Route::post('prepare-github-issue', [ParserBugReportController::class, 'prepare'])->name('prepare-github-issue');
        });
        Route::post('parser-bug-reports/{parserBugReport}/approve', [ParserBugReportController::class, 'approve'])
            ->name('parser-bug-reports.approve');
        Route::get('species', [SpeciesAliasController::class, 'index'])->name('species-aliases.index');
        Route::post('species', [SpeciesAliasController::class, 'storeSpecies'])->name('species.store');
        Route::patch('species/{species}', [SpeciesAliasController::class, 'updateSpecies'])->name('species.update');
        Route::post('species/aliases', [SpeciesAliasController::class, 'store'])->name('species-aliases.store');
        Route::get('trip-types', [TripTypeAliasController::class, 'index'])->name('trip-type-aliases.index');
        Route::post('trip-types', [TripTypeAliasController::class, 'storeTripType'])->name('trip-types.store');
        Route::patch('trip-types/{tripType}', [TripTypeAliasController::class, 'updateTripType'])->name('trip-types.update');
        Route::post('trip-types/aliases', [TripTypeAliasController::class, 'store'])->name('trip-type-aliases.store');
        Route::get('notification-logs', NotificationDeliveryController::class)->name('notification-logs.index');
        Route::get('failed-jobs', FailedJobController::class)->name('failed-jobs.index');
    });
});

require __DIR__.'/auth.php';
