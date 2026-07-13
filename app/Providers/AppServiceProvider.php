<?php

namespace App\Providers;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\Contracts\IssueTracking\IssueTracker;
use App\Services\AI\DisabledParserDiagnosticReviewer;
use App\Services\AI\OpenAiParserDiagnosticReviewer;
use App\Services\IssueTracking\GitHubIssueTracker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ParserDiagnosticReviewer::class,
            (bool) config('fish.ai_review.enabled') && filled(config('services.openai.api_key'))
                ? OpenAiParserDiagnosticReviewer::class
                : DisabledParserDiagnosticReviewer::class,
        );

        $this->app->bind(IssueTracker::class, GitHubIssueTracker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn ($user): bool => $user->isAdmin());

        RateLimiter::for('ai-parser-reviews', fn (): Limit => Limit::perMinute(
            max(1, (int) config('fish.ai_review.rate_limit_per_minute')),
        )->by('openai-parser-reviews'));

        RateLimiter::for('github-parser-issues', fn (): Limit => Limit::perMinute(
            max(1, (int) config('fish.github_issues.rate_limit_per_minute')),
        )->by('github-parser-issues'));
    }
}
