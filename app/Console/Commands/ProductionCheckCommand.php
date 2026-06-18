<?php

namespace App\Console\Commands;

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
        $this->assert(config('database.default') !== 'sqlite', 'Database driver is not SQLite.', 'Production must use PostgreSQL, not SQLite.', $failures);
        $this->assert(config('queue.default') === 'redis', 'Queue connection is Redis.', 'QUEUE_CONNECTION should be redis for production workers.', $failures);
        $this->assert(config('cache.default') === 'redis', 'Cache store is Redis.', 'CACHE_STORE should be redis for production.', $failures);
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

        if ($this->option('skip-database')) {
            $warnings[] = 'Database reachability check skipped.';
        } else {
            $this->checkDatabase($failures);
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
}
