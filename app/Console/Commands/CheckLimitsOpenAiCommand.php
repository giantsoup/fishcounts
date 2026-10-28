<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('fish:limits-live-check')]
#[Description('Run the opt-in GPT-6 Luna High parser check with a private process credential and isolated SQLite database')]
class CheckLimitsOpenAiCommand extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local') || blank(config('services.openai.api_key')) || ! is_file(base_path('vendor/phpunit/phpunit/phpunit'))) {
            $this->error('This check requires local development, PHPUnit, and a configured OpenAI key.');

            return self::FAILURE;
        }
        $process = new Process([PHP_BINARY, base_path('vendor/phpunit/phpunit/phpunit'), '--testdox', 'tests/Feature/LiveLimitsOpenAiTest.php'], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'FISH_RUN_LIVE_OPENAI_TESTS' => '1', 'FISH_LIVE_OPENAI_API_KEY' => config('services.openai.api_key'),
            'FISH_ISOLATED_TEST_DATABASE' => false,
        ], timeout: 180);
        try {
            return $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));
        } catch (Throwable) {
            $this->error('Live check could not complete. No credential or process environment is included in this error.');

            return self::FAILURE;
        }
    }
}
