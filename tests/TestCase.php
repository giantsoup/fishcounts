<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
        $this->assertSafeTestDatabase($connection, $app->environment(), getenv('FISH_ISOLATED_TEST_DATABASE') ?: null);

        return $app;
    }

    /** @param array<string, mixed> $connection */
    public function assertSafeTestDatabase(array $connection, string $environment, ?string $isolatedDatabase = null): void
    {
        $memory = ($connection['driver'] ?? null) === 'sqlite' && ($connection['database'] ?? null) === ':memory:';
        $isolated = ($connection['driver'] ?? null) === 'mysql'
            && in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            && preg_match('/^fishcounts_limits_qa_[a-f0-9]{8}$/', $isolatedDatabase ?? '') === 1
            && ($connection['database'] ?? null) === $isolatedDatabase;
        if ($environment !== 'testing' || filled($connection['url'] ?? null) || (! $memory && ! $isolated)) {
            throw new LogicException('Tests require SQLite :memory: or an explicitly isolated local MySQL QA database. Refusing to refresh an application database.');
        }
    }
}
