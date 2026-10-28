<?php

namespace Tests\Unit;

use Hamcrest\Matchers;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderBag;

class MockeryCompatibilityTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_partial_mock_runs_the_real_constructor_and_matches_hamcrest_arguments(): void
    {
        $headers = Mockery::mock(HeaderBag::class)->makePartial();
        $headers->__construct(['X-Original' => 'original']);
        $headers->shouldReceive('get')->once()->with(Matchers::equalTo('X-Matched'))->andReturn('matched');

        $this->assertSame('original', $headers->get('X-Original'));
        $this->assertSame('matched', $headers->get('X-Matched'));

        $copy = clone $headers;
        $copy->set('X-Original', 'changed');
        $this->assertSame('changed', $copy->get('X-Original'));
        $this->assertSame('original', $headers->get('X-Original'));
    }

    public function test_mocked_transport_exception_preserves_its_type_and_message(): void
    {
        $http = Mockery::mock(Factory::class);
        $http->shouldReceive('get')->once()->with(Matchers::startsWith('https://fixture.invalid/'))
            ->andThrow(new ConnectionException('fixture timeout'));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('fixture timeout');

        $http->get('https://fixture.invalid/timeout');
    }
}
