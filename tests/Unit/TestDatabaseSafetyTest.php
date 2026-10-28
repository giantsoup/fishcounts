<?php

namespace Tests\Unit;

use LogicException;
use Tests\TestCase;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_application_database_is_rejected_before_refreshing_tables(): void
    {
        $this->expectException(LogicException::class);
        $this->assertSafeTestDatabase(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'fishcounts'], 'testing');
    }

    public function test_sqlite_memory_is_accepted_in_testing_only(): void
    {
        $this->assertSafeTestDatabase(['driver' => 'sqlite', 'database' => ':memory:'], 'testing');
        $this->expectException(LogicException::class);
        $this->assertSafeTestDatabase(['driver' => 'sqlite', 'database' => ':memory:'], 'local');
    }

    public function test_inherited_database_urls_are_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->assertSafeTestDatabase(['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'mysql://localhost/fishcounts'], 'testing');
    }
}
