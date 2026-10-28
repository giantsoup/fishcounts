<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class UuidCompatibilityTest extends TestCase
{
    public function test_laravel_execution_identifiers_are_valid_unique_and_round_trip_through_integer_arithmetic(): void
    {
        $identifiers = [];

        for ($index = 0; $index < 100; $index++) {
            $identifier = (string) Str::uuid();
            $this->assertTrue(Uuid::isValid($identifier));
            $uuid = Uuid::fromString($identifier);
            $this->assertSame($identifier, Uuid::fromInteger($uuid->getInteger()->toString())->toString());
            $this->assertSame($identifier, Uuid::fromBytes($uuid->getBytes())->toString());
            $identifiers[] = $identifier;
        }

        $this->assertCount(100, array_unique($identifiers));
        $this->assertTrue(Uuid::isValid((string) Str::orderedUuid()));
    }
}
