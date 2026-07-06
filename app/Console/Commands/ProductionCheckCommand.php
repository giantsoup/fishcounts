<?php

namespace App\Console\Commands;

use App\Enums\EnvironmentalLocationType;
use App\Models\EnvironmentalSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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

        if ($this->option('skip-database')) {
            $warnings[] = 'Database reachability check skipped.';
        } else {
            $this->checkDatabase($failures);
            $this->checkEnvironmentalSources($failures);
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
}
