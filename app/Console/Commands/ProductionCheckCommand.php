<?php

namespace App\Console\Commands;

use App\Enums\BookingProvider;
use App\Enums\EnvironmentalLocationType;
use App\Jobs\CreateParserBugIssueJob;
use App\Jobs\ProcessHistoricalAiReviewRunItemJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\Boat;
use App\Models\EnvironmentalSource;
use App\Models\Landing;
use DateTimeZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('fish:production-check {--skip-database : Validate config only without opening a database connection}')]
#[Description('Validate production-critical configuration before deployment.')]
class ProductionCheckCommand extends Command
{
    public function handle(): int
    {
        $failures = [];
        $warnings = [];

        $this->assert((bool) config('app.key'), 'APP_KEY is configured.', 'APP_KEY is missing.', $failures);
        $this->assert(! (bool) config('app.debug'), 'APP_DEBUG is disabled.', 'APP_DEBUG must be false in production.', $failures);
        $this->assert(config('database.default') !== 'sqlite', 'Database driver is not SQLite.', 'Production must use a production database such as MariaDB, MySQL, or PostgreSQL.', $failures);
        $this->assert(config('queue.default') === 'database', 'Queue connection uses the database driver for this VPS deployment.', 'QUEUE_CONNECTION should be database for this VPS deployment.', $failures);
        $this->assert(config('cache.default') === 'database', 'Cache store uses the database driver for this VPS deployment.', 'CACHE_STORE should be database for this VPS deployment.', $failures);
        $this->assert((bool) config('session.secure'), 'Secure session cookies are enabled.', 'SESSION_SECURE_COOKIE must be true behind HTTPS.', $failures);
        $this->assert((bool) config('session.http_only'), 'HTTP-only session cookies are enabled.', 'SESSION_HTTP_ONLY must stay true.', $failures);

        $mailMailer = (string) config('mail.default');
        $this->assert(! in_array($mailMailer, ['array', 'log'], true), 'Mail transport is not a local-only driver.', 'MAIL_MAILER must be a real production transport.', $failures);
        $this->assert((bool) config('mail.from.address'), 'Mail from address is configured.', 'MAIL_FROM_ADDRESS is required for password resets and digests.', $failures);

        $this->assert(
            config('fish.admin.password') !== 'password',
            'Default admin password override is configured.',
            'FISH_ADMIN_PASSWORD must not use the default password.',
            $failures
        );

        $this->checkConditionConfiguration($failures);
        $this->checkAiReviewConfiguration($failures);

        if ($this->option('skip-database')) {
            $warnings[] = 'Database reachability check skipped.';
        } else {
            $this->checkDatabase($failures);
            $this->checkEnvironmentalSources($failures);
            $this->checkBookingReferenceData($failures);
        }

        if ((string) config('app.env') !== 'production') {
            $warnings[] = 'APP_ENV is not production; this is acceptable for staging smoke tests only.';
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if ($failures !== []) {
            $this->newLine();
            $this->error(count($failures).' production check(s) failed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Production checks passed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function checkAiReviewConfiguration(array &$failures): void
    {
        $dailyLimit = (int) config('fish.ai_review.budgets.daily_limit_micros');
        $monthlyLimit = (int) config('fish.ai_review.budgets.monthly_limit_micros');
        $estimatedRequestCost = (int) config('fish.ai_review.budgets.estimated_request_cost_micros');
        $budgetTimezone = (string) config('fish.ai_review.budgets.timezone');
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $largestJobTimeout = max(
            (new ReviewParserDiagnosticsJob(0))->timeout,
            (new CreateParserBugIssueJob(0))->timeout,
            (new ProcessHistoricalAiReviewRunItemJob(0))->timeout,
        );
        $databaseCache = Cache::store('database')->getStore();

        $this->assert($dailyLimit >= 0, 'Optional daily AI budget limit is valid.', 'FISH_AI_REVIEW_DAILY_LIMIT_MICROS must be zero or greater.', $failures);
        $this->assert($monthlyLimit > 0 && ($dailyLimit === 0 || $monthlyLimit >= $dailyLimit), 'Monthly AI budget hard limit is configured.', 'FISH_AI_REVIEW_MONTHLY_LIMIT_MICROS must be positive and at least any enabled daily limit.', $failures);
        $this->assert($estimatedRequestCost > 0, 'AI estimated request cost is configured.', 'FISH_AI_REVIEW_ESTIMATED_REQUEST_COST_MICROS must be greater than zero.', $failures);
        $this->assert(in_array($budgetTimezone, DateTimeZone::listIdentifiers(), true), 'AI budget timezone is valid.', 'FISH_AI_REVIEW_BUDGET_TIMEZONE must be a valid timezone identifier.', $failures);
        $this->assert((bool) config('fish.ai_review.budgets.hard_stop'), 'AI budget hard stop is enabled.', 'AI budget hard stop must remain enabled.', $failures);
        $this->assert($databaseCache instanceof LockProvider, 'Database cache supports atomic locks.', 'The database cache store must support atomic locks for uniqueness, scheduling, and rate limiting.', $failures);
        $this->assert($retryAfter > $largestJobTimeout, 'Database queue retry_after exceeds every AI/GitHub job timeout.', "DB_QUEUE_RETRY_AFTER must exceed {$largestJobTimeout} seconds.", $failures);

        if (config('fish.ai_review.enabled')) {
            $this->assert(filled(config('services.openai.api_key')), 'OpenAI credentials are provisioned.', 'OPENAI_API_KEY is required when AI reviews are enabled.', $failures);
        }

        if (config('fish.github_issues.enabled')) {
            $githubCredentialsReady = filled(config('services.github_app.client_id'))
                && filled(config('services.github_app.installation_id'))
                && (filled(config('services.github_app.private_key_path')) || filled(config('services.github_app.private_key_base64')));
            $this->assert($githubCredentialsReady, 'GitHub App credentials are provisioned.', 'GitHub App credentials are required when GitHub parser issues are enabled.', $failures);
        }
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function assert(bool $condition, string $success, string $failure, array &$failures): void
    {
        if ($condition) {
            $this->components->info($success);

            return;
        }

        $this->components->error($failure);
        $failures[] = $failure;
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function checkDatabase(array &$failures): void
    {
        try {
            DB::connection()->getPdo();
            $this->components->info('Database connection is reachable.');
        } catch (\Throwable $throwable) {
            $message = 'Database connection failed: '.$throwable->getMessage();
            $this->components->error($message);
            $failures[] = $message;
        }
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function checkConditionConfiguration(array &$failures): void
    {
        $sources = config('fish.conditions.sources', []);
        $profiles = config('fish.conditions.profiles', []);
        $allowedHosts = config('fish.conditions.allowed_hosts', []);
        $userAgent = (string) config('fish.conditions.user_agent', '');
        $timeoutSeconds = (int) config('fish.conditions.timeout_seconds', 0);
        $connectTimeoutSeconds = (int) config('fish.conditions.connect_timeout_seconds', 0);

        $this->assert(is_array($sources) && $sources !== [], 'Environmental condition sources are configured.', 'FISH condition sources must not be empty.', $failures);
        $this->assert(is_array($profiles) && $profiles !== [], 'Environmental condition profiles are configured.', 'FISH condition profiles must not be empty.', $failures);
        $this->assert(is_array($allowedHosts) && $allowedHosts !== [], 'Environmental condition allowed hosts are configured.', 'FISH condition allowed hosts must not be empty.', $failures);
        $this->assert($userAgent !== '' && ! str_contains($userAgent, 'example.com'), 'Environmental condition user agent is production-ready.', 'FISH_CONDITIONS_USER_AGENT or FISH_SCRAPER_USER_AGENT must be set to a real contact URL, not example.com.', $failures);
        $this->assert($timeoutSeconds > 0, 'Environmental condition request timeout is configured.', 'FISH_CONDITIONS_TIMEOUT must be greater than zero.', $failures);
        $this->assert($connectTimeoutSeconds > 0 && $connectTimeoutSeconds <= $timeoutSeconds, 'Environmental condition connect timeout is configured.', 'FISH_CONDITIONS_CONNECT_TIMEOUT must be greater than zero and no larger than FISH_CONDITIONS_TIMEOUT.', $failures);

        if (! is_array($sources) || ! is_array($profiles)) {
            return;
        }

        $profileSources = [];

        foreach ($profiles as $slug => $profile) {
            if (! is_array($profile)) {
                $this->components->error("Environmental condition profile [{$slug}] must be an array.");
                $failures[] = "Environmental condition profile [{$slug}] must be an array.";

                continue;
            }

            $profileSourceSlugs = $profile['sources'] ?? [];
            $locationType = (string) ($profile['location_type'] ?? '');
            $latitude = $profile['latitude'] ?? null;
            $longitude = $profile['longitude'] ?? null;

            $this->assert(is_array($profileSourceSlugs) && $profileSourceSlugs !== [], "Environmental condition profile [{$slug}] has sources.", "Environmental condition profile [{$slug}] must include at least one source.", $failures);
            $this->assert(EnvironmentalLocationType::tryFrom($locationType) !== null, "Environmental condition profile [{$slug}] has a valid location type.", "Environmental condition profile [{$slug}] has invalid location_type [{$locationType}].", $failures);
            $this->assert(is_numeric($latitude) && (float) $latitude >= -90 && (float) $latitude <= 90, "Environmental condition profile [{$slug}] has a valid latitude.", "Environmental condition profile [{$slug}] must have a valid latitude.", $failures);
            $this->assert(is_numeric($longitude) && (float) $longitude >= -180 && (float) $longitude <= 180, "Environmental condition profile [{$slug}] has a valid longitude.", "Environmental condition profile [{$slug}] must have a valid longitude.", $failures);

            if (is_array($profileSourceSlugs)) {
                array_push($profileSources, ...$profileSourceSlugs);
            }
        }

        $missingProfileSources = array_values(array_diff($sources, $profileSources));
        $unknownProfileSources = array_values(array_diff($profileSources, $sources));

        $this->assert($missingProfileSources === [], 'Every enabled environmental source is assigned to a condition profile.', 'These environmental sources are enabled but not assigned to a profile: '.implode(', ', $missingProfileSources), $failures);
        $this->assert($unknownProfileSources === [], 'Every profile environmental source is enabled in condition sources.', 'These environmental profile sources are not enabled: '.implode(', ', $unknownProfileSources), $failures);
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function checkEnvironmentalSources(array &$failures): void
    {
        try {
            $configuredSources = config('fish.conditions.sources', []);

            if (! is_array($configuredSources) || $configuredSources === []) {
                return;
            }

            $seededSources = EnvironmentalSource::query()
                ->whereIn('slug', $configuredSources)
                ->where('is_enabled', true)
                ->pluck('slug')
                ->all();

            $missingSources = array_values(array_diff($configuredSources, $seededSources));

            $this->assert($missingSources === [], 'Enabled environmental source reference data is seeded.', 'Missing enabled environmental source rows: '.implode(', ', $missingSources).'. Run php artisan db:seed --class=EnvironmentalSourceSeeder --force.', $failures);
        } catch (\Throwable $throwable) {
            $message = 'Environmental source reference data check failed: '.$throwable->getMessage();
            $this->components->error($message);
            $failures[] = $message;
        }
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function checkBookingReferenceData(array &$failures): void
    {
        try {
            $expectedLandings = [
                'fishermans-landing' => [BookingProvider::FishingReservations, 'https://fishermanslanding.fishingreservations.net/resos/'],
                'seaforth-sportfishing' => [BookingProvider::FishingReservations, 'https://seaforth.fishingreservations.net/sales/'],
                'hm-landing' => [BookingProvider::HmLanding, 'https://www.hmlanding.com'],
                'point-loma-sportfishing' => [BookingProvider::FishingReservations, 'https://pointloma.fishingreservations.net/sales/'],
            ];
            $landings = Landing::query()
                ->whereIn('slug', array_keys($expectedLandings))
                ->get()
                ->keyBy('slug');

            foreach ($expectedLandings as $slug => [$provider, $baseUrl]) {
                $landing = $landings->get($slug);

                $this->assert(
                    $landing !== null
                        && $landing->booking_provider === $provider
                        && $landing->booking_base_url === $baseUrl,
                    "Booking provider metadata is configured for [{$slug}].",
                    "Booking provider metadata is missing or invalid for [{$slug}]. Run pending migrations.",
                    $failures,
                );
            }

            $sanDiego = Boat::query()
                ->where('slug', 'san-diego')
                ->whereHas('landing', fn ($query) => $query->where('slug', 'seaforth-sportfishing'))
                ->first();

            $this->assert(
                filled($sanDiego?->booking_provider_identifier),
                'San Diego has a provider-specific booking identifier.',
                'San Diego is missing its provider booking identifier. Run php artisan booking:sync-provider-identifiers.',
                $failures,
            );
        } catch (\Throwable $throwable) {
            $message = 'Booking reference data check failed: '.$throwable->getMessage();
            $this->components->error($message);
            $failures[] = $message;
        }
    }
}
