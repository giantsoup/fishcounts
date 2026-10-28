<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HttpTransportCompatibilityTest extends TestCase
{
    private Process $server;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        $this->baseUrl = 'http://'.$address;
        $this->server = new Process([PHP_BINARY, '-S', $address, base_path('tests/Fixtures/http-transport.php')], base_path());
        $this->server->start();
        $deadline = microtime(true) + 5;

        do {
            try {
                if ($this->request()->get($this->baseUrl.'/ok')->ok()) {
                    return;
                }
            } catch (ConnectionException) {
                usleep(10000);
            }
        } while (microtime(true) < $deadline && $this->server->isRunning());

        $this->fail('Local HTTP fixture did not start: '.$this->server->getErrorOutput());
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop(1);
        }

        parent::tearDown();
    }

    public function test_real_transport_follows_redirects_and_preserves_json_response_headers(): void
    {
        $response = $this->request()->get($this->baseUrl.'/redirect');

        $this->assertTrue($response->ok());
        $this->assertSame('/ok', $response->json('path'));
        $this->assertStringContainsString('application/json', $response->header('Content-Type'));
    }

    public function test_real_transport_exposes_a_readable_response_stream(): void
    {
        $response = $this->request()->withOptions(['stream' => true])->get($this->baseUrl.'/stream');

        $this->assertSame(str_repeat('stream-data-', 1024), $response->toPsrResponse()->getBody()->getContents());
    }

    public function test_real_transport_retains_failed_response_when_retry_throw_is_disabled(): void
    {
        $response = $this->request()->retry([1], throw: false)->get($this->baseUrl.'/failure');

        $this->assertSame(503, $response->status());
        $this->expectException(RequestException::class);
        $response->throw();
    }

    public function test_real_transport_maps_timeout_to_laravel_connection_exception(): void
    {
        $this->expectException(ConnectionException::class);

        $this->request()->timeout(0.05)->get($this->baseUrl.'/timeout');
    }

    public function test_real_transport_maps_connection_refusal_to_laravel_connection_exception(): void
    {
        $this->server->stop(1);
        $this->expectException(ConnectionException::class);

        $this->request()->get($this->baseUrl.'/ok');
    }

    public function test_real_transport_keeps_cookies_and_resolves_promises(): void
    {
        $client = new Client(['base_uri' => $this->baseUrl, 'proxy' => '', 'timeout' => 2, 'connect_timeout' => 1, 'cookies' => new CookieJar]);
        $client->get('/cookie');
        $result = $client->getAsync('/ok')->then(fn (ResponseInterface $response): array => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR))->wait();

        $this->assertSame('verified', $result['cookie']);
        $this->assertSame('/ok', $result['path']);
    }

    private function request(): PendingRequest
    {
        return (new Factory)->withOptions(['proxy' => ''])->connectTimeout(1)->timeout(2);
    }
}
