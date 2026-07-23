<?php

namespace App\Console\Commands;

use App\Enums\BookingProvider;
use App\Enums\EnvironmentalLocationType;
use App\Enums\ParserEngine;
use App\Jobs\CreateParserBugIssueJob;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Jobs\FinalizeHistoricalAiReviewRunItemJob;
use App\Jobs\ParseRawPayloadJob;
use App\Jobs\ProcessHistoricalAiReviewRunItemJob;
use App\Jobs\ReparseBackfillPayloadJob;
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
        $this->assert(in_array(config('queue.default'), ['database', 'redis'], true), 'Queue connection uses a supported production driver.', 'QUEUE_CONNECTION must be database or redis.', $failures);
        $this->assert(in_array(config('cache.default'), ['database', 'redis'], true), 'Cache store uses a supported production driver.', 'CACHE_STORE must be database or redis.', $failures);
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
        $this->checkAiParsingConfiguration($failures);

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
            (new DispatchParserDiagnosticReviewBatchesJob(0))->timeout,
            (new FinalizeHistoricalAiReviewRunItemJob(0))->timeout,
            (new ReviewParserDiagnosticsJob(0))->timeout,
            (new CreateParserBugIssueJob(0))->timeout,
            (new ProcessHistoricalAiReviewRunItemJob(0))->timeout,
        );
        $databaseCache = Cache::store('database')->getStore();
        $maxDiagnosticsPerRequest = (int) config('fish.ai_review.limits.max_diagnostics_per_request');
        $maxInputTokens = (int) config('fish.ai_review.limits.max_input_tokens');
        $maxOutputTokens = (int) config('fish.ai_review.limits.max_output_tokens');
        $model = (string) config('fish.ai_review.model');
        $pricingModel = (string) config('fish.ai_review.pricing.model');
        $pricingServiceTier = (string) config('fish.ai_review.pricing.service_tier');
        $pricingRates = [
            (int) config('fish.ai_review.pricing.input_cost_per_million_micros'),
            (int) config('fish.ai_review.pricing.cached_input_cost_per_million_micros'),
            (int) config('fish.ai_review.pricing.cache_write_cost_per_million_micros'),
            (int) config('fish.ai_review.pricing.output_cost_per_million_micros'),
        ];
        $maximumRequestCost = intdiv(
            ($maxInputTokens * max(array_slice($pricingRates, 0, 3)))
                + ($maxOutputTokens * $pricingRates[3])
                + 999_999,
            1_000_000,
        );

        $this->assert($dailyLimit >= 0, 'Optional daily AI budget limit is valid.', 'FISH_AI_REVIEW_DAILY_LIMIT_MICROS must be zero or greater.', $failures);
        $this->assert($monthlyLimit > 0 && ($dailyLimit === 0 || $monthlyLimit >= $dailyLimit), 'Monthly AI budget hard limit is configured.', 'FISH_AI_REVIEW_MONTHLY_LIMIT_MICROS must be positive and at least any enabled daily limit.', $failures);
        $this->assert($estimatedRequestCost > 0, 'AI estimated request cost is configured.', 'FISH_AI_REVIEW_ESTIMATED_REQUEST_COST_MICROS must be greater than zero.', $failures);
        $this->assert($pricingModel !== '' && $model === $pricingModel, 'AI model has matching token pricing.', 'FISH_AI_REVIEW_PRICING_MODEL must exactly match FISH_AI_REVIEW_MODEL.', $failures);
        $this->assert($pricingServiceTier === 'default', 'AI pricing uses the standard service tier.', 'FISH_AI_REVIEW_PRICING_SERVICE_TIER must be default unless tier-specific pricing is implemented.', $failures);
        $this->assert(! in_array(true, array_map(fn (int $rate): bool => $rate <= 0, $pricingRates), true), 'AI token pricing is configured.', 'Every FISH_AI_REVIEW_*_COST_PER_MILLION_MICROS value must be greater than zero.', $failures);
        $this->assert($estimatedRequestCost >= $maximumRequestCost, 'AI reservation covers the maximum configured request cost.', "FISH_AI_REVIEW_ESTIMATED_REQUEST_COST_MICROS must be at least {$maximumRequestCost} for the configured token limits.", $failures);
        $this->assert($maxDiagnosticsPerRequest > 0, 'AI diagnostic batch size is configured.', 'FISH_AI_REVIEW_MAX_DIAGNOSTICS_PER_REQUEST must be greater than zero.', $failures);
        $this->assert($maxInputTokens > 0, 'AI input-token limit is configured.', 'FISH_AI_REVIEW_MAX_INPUT_TOKENS must be greater than zero.', $failures);
        $this->assert($maxOutputTokens > 0, 'AI output-token limit is configured.', 'FISH_AI_REVIEW_MAX_OUTPUT_TOKENS must be greater than zero.', $failures);
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
    private function checkAiParsingConfiguration(array &$failures): void
    {
        $dailyLimit = (int) config('fish.ai_parsing.budgets.daily_limit_micros');
        $monthlyLimit = (int) config('fish.ai_parsing.budgets.monthly_limit_micros');
        $estimatedAttemptCost = (int) config('fish.ai_parsing.budgets.estimated_attempt_cost_micros');
        $budgetTimezone = (string) config('fish.ai_parsing.budgets.timezone');
        $maxInputTokens = (int) config('fish.ai_parsing.limits.max_input_tokens');
        $maxOutputTokens = (int) config('fish.ai_parsing.limits.max_output_tokens');
        $maxReports = (int) config('fish.ai_parsing.limits.max_reports');
        $maxSpeciesPerReport = (int) config('fish.ai_parsing.limits.max_species_per_report');
        $maxAnglers = (int) config('fish.ai_parsing.limits.max_anglers');
        $maxCount = (int) config('fish.ai_parsing.limits.max_count');
        $connectTimeout = (int) config('fish.ai_parsing.connect_timeout_seconds');
        $requestTimeout = (int) config('fish.ai_parsing.timeout_seconds');
        $configuredJobTimeout = (int) config('fish.ai_parsing.job_timeout_seconds');
        $lockSeconds = (int) config('fish.ai_parsing.lock_seconds');
        $reservationTtlSeconds = (int) config('fish.ai_parsing.budgets.reservation_ttl_minutes') * 60;
        $rateLimit = (int) config('fish.ai_parsing.rate_limit_per_minute');
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $model = (string) config('fish.ai_parsing.model');
        $pricingModel = (string) config('fish.ai_parsing.pricing.model');
        $serviceTier = (string) config('fish.ai_parsing.service_tier');
        $pricingServiceTier = (string) config('fish.ai_parsing.pricing.service_tier');
        $applicationQueueConnection = (string) config('fish.queues.application_connection');
        $pricingRates = [
            (int) config('fish.ai_parsing.pricing.input_cost_per_million_micros'),
            (int) config('fish.ai_parsing.pricing.cached_input_cost_per_million_micros'),
            (int) config('fish.ai_parsing.pricing.cache_write_cost_per_million_micros'),
            (int) config('fish.ai_parsing.pricing.output_cost_per_million_micros'),
        ];
        $maximumAttemptCost = intdiv(
            ($maxInputTokens * max(array_slice($pricingRates, 0, 3)))
                + ($maxOutputTokens * $pricingRates[3])
                + 999_999,
            1_000_000,
        );
        $aiParseJob = new ParseRawPayloadJob(0, parserEngine: ParserEngine::Ai);
        $aiBackfillJob = new ReparseBackfillPayloadJob(0, 0, ParserEngine::Ai);
        $minimumJobTimeout = ($requestTimeout * 2) + 10;
        $largestAiJobTimeout = max($aiParseJob->timeout, $aiBackfillJob->timeout);

        $this->assert($dailyLimit > 0, 'AI parser daily budget hard limit is configured.', 'FISH_AI_PARSING_DAILY_LIMIT_MICROS must be greater than zero.', $failures);
        $this->assert($monthlyLimit >= $dailyLimit, 'AI parser monthly budget hard limit is configured.', 'FISH_AI_PARSING_MONTHLY_LIMIT_MICROS must be at least the daily limit.', $failures);
        $this->assert($estimatedAttemptCost > 0, 'AI parser attempt reservation is configured.', 'FISH_AI_PARSING_ESTIMATED_ATTEMPT_COST_MICROS must be greater than zero.', $failures);
        $this->assert($model === 'gpt-5.6-luna' && $pricingModel === $model, 'AI parser model has verified Luna token pricing.', 'FISH_AI_PARSING_MODEL and FISH_AI_PARSING_PRICING_MODEL must remain gpt-5.6-luna until another model pricing table is implemented.', $failures);
        $this->assert($serviceTier === 'default' && $serviceTier === $pricingServiceTier, 'AI parser uses matching standard-tier pricing.', 'FISH_AI_PARSING_SERVICE_TIER and FISH_AI_PARSING_PRICING_SERVICE_TIER must both be default.', $failures);
        $this->assert(! in_array(true, array_map(fn (int $rate): bool => $rate <= 0, $pricingRates), true), 'AI parser token pricing is configured.', 'Every AI parser token pricing rate must be greater than zero.', $failures);
        $this->assert($estimatedAttemptCost >= $maximumAttemptCost, 'AI parser reservation covers the maximum configured request cost.', "FISH_AI_PARSING_ESTIMATED_ATTEMPT_COST_MICROS must be at least {$maximumAttemptCost} for the configured token limits.", $failures);
        $this->assert($maxInputTokens > 0 && $maxInputTokens <= 64_000, 'AI parser input guardrail is configured.', 'FISH_AI_PARSING_MAX_INPUT_TOKENS must be between 1 and 64000.', $failures);
        $this->assert($maxOutputTokens > 0 && $maxOutputTokens <= 16_000, 'AI parser output guardrail is configured.', 'FISH_AI_PARSING_MAX_OUTPUT_TOKENS must be between 1 and 16000.', $failures);
        $this->assert($maxReports > 0 && $maxSpeciesPerReport > 0 && $maxAnglers > 0 && $maxCount > 0, 'AI parser domain limits are configured.', 'Every AI parser domain limit must be greater than zero.', $failures);
        $this->assert($connectTimeout > 0 && $connectTimeout <= 5, 'AI parser connection timeout is bounded.', 'FISH_AI_PARSING_CONNECT_TIMEOUT must be between 1 and 5 seconds.', $failures);
        $this->assert($requestTimeout >= $connectTimeout && $requestTimeout <= 120, 'AI parser request timeout is bounded.', 'FISH_AI_PARSING_TIMEOUT must be at least the connect timeout and no greater than 120 seconds.', $failures);
        $this->assert($configuredJobTimeout >= $minimumJobTimeout && $largestAiJobTimeout === $configuredJobTimeout, 'AI parser job timeout covers both provider attempts.', "FISH_AI_PARSING_JOB_TIMEOUT must be at least {$minimumJobTimeout} seconds and apply to every AI parser job.", $failures);
        $this->assert($lockSeconds >= $largestAiJobTimeout, 'AI parser source/date lock covers the complete job.', "FISH_AI_PARSING_LOCK_SECONDS must be at least {$largestAiJobTimeout} seconds.", $failures);
        $this->assert($reservationTtlSeconds > $requestTimeout, 'AI parser budget reservation outlives one provider attempt.', 'FISH_AI_PARSING_RESERVATION_TTL_MINUTES must exceed the configured request timeout.', $failures);
        $this->assert($rateLimit > 0 && $rateLimit <= 5, 'AI parser request-rate guardrail is configured.', 'FISH_AI_PARSING_RATE_LIMIT_PER_MINUTE must be between 1 and 5.', $failures);
        $this->assert(in_array($budgetTimezone, DateTimeZone::listIdentifiers(), true), 'AI parser budget timezone is valid.', 'FISH_AI_PARSING_BUDGET_TIMEZONE must be a valid timezone identifier.', $failures);
        $this->assert(in_array((string) config('fish.ai_parsing.reasoning_effort'), ['low', 'medium', 'high'], true), 'AI parser reasoning effort is valid.', 'FISH_AI_PARSING_REASONING_EFFORT must be low, medium, or high.', $failures);
        $this->assert(collect(['prompt_version', 'schema_version', 'sanitizer_version', 'catalog_version'])->every(fn (string $key): bool => filled(config("fish.ai_parsing.{$key}"))), 'AI parser component versions are configured.', 'Every AI parser prompt, schema, sanitizer, and catalog version must be non-empty.', $failures);
        $this->assert((int) config('fish.ai_parsing.retention.snapshot_months') >= 3, 'AI parser snapshot retention is at least three months.', 'FISH_AI_PARSING_SNAPSHOT_RETENTION_MONTHS must be at least 3.', $failures);
        $this->assert(config('fish.ai_parsing.store_provider_response') === false, 'AI parser provider response storage is disabled.', 'AI parser provider responses must not be stored.', $failures);
        $this->assert(Cache::store('database')->getStore() instanceof LockProvider, 'Database cache supports AI parser locks.', 'The database cache store must support atomic AI parser locks.', $failures);
        $minimumRetryAfter = max($largestAiJobTimeout, $lockSeconds);
        $this->assert($retryAfter > $minimumRetryAfter, 'Database queue retry_after exceeds the AI parser execution envelope.', "DB_QUEUE_RETRY_AFTER must exceed the {$minimumRetryAfter}-second AI parser lock and job envelope.", $failures);
        $this->assert($aiParseJob->connection === 'database' && $aiParseJob->queue === 'ai-primary-parsing', 'AI parsing jobs use the dedicated database queue.', 'AI parsing jobs must use database:ai-primary-parsing.', $failures);
        $this->assert($aiBackfillJob->connection === 'database' && $aiBackfillJob->queue === 'ai-primary-parsing', 'AI backfill jobs use the dedicated database queue.', 'AI backfill jobs must use database:ai-primary-parsing.', $failures);
        $this->assert($aiParseJob->maxExceptions === 1 && $aiBackfillJob->maxExceptions === 1, 'AI jobs fail after the first unhandled exception.', 'AI parsing jobs must not retry permanent failures indefinitely.', $failures);
        $this->assert($aiParseJob->failOnTimeout && $aiBackfillJob->failOnTimeout, 'AI jobs fail cleanly on worker timeout.', 'AI parsing jobs must fail instead of retrying indefinitely after a worker timeout.', $failures);
        $this->assert($aiParseJob->uniqueFor >= 86_400 && $aiBackfillJob->uniqueFor >= 86_400, 'AI job uniqueness covers prolonged queue delays.', 'AI parsing job uniqueness must prevent duplicate billable work for at least one day.', $failures);
        $this->assert(array_key_exists($applicationQueueConnection, (array) config('queue.connections')), 'Application downstream queue connection is configured.', 'FISH_APPLICATION_QUEUE_CONNECTION must name a configured Laravel queue connection.', $failures);

        if (config('fish.ai_parsing.enabled')) {
            $this->assert(filled(config('services.openai.api_key')), 'AI parser OpenAI credentials are provisioned.', 'OPENAI_API_KEY is required when AI primary parsing is enabled.', $failures);
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
