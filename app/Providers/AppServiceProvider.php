<?php

namespace App\Providers;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\Services\AI\DisabledParserDiagnosticReviewer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ParserDiagnosticReviewer::class, DisabledParserDiagnosticReviewer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn ($user): bool => $user->isAdmin());
    }
}
