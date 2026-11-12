<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiRouteAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_ABILITIES = ['read', 'aliases', 'entities', 'reparse', 'errors'];

    public function test_every_api_route_rejects_requests_without_a_token(): void
    {
        $this->sweepApiRoutes(function (string $method, string $path): void {
            $this->json($method, $path)->assertUnauthorized();
        });
    }

    public function test_every_api_route_rejects_a_non_admin_token(): void
    {
        $token = User::factory()->create()->createToken('agent', self::ALL_ABILITIES)->plainTextToken;

        $this->sweepApiRoutes(function (string $method, string $path) use ($token): void {
            $this->withToken($token)->json($method, $path)->assertForbidden();
        });
    }

    public function test_every_api_route_rejects_an_inactive_admin_token(): void
    {
        $token = User::factory()->admin()->create(['is_active' => false])
            ->createToken('agent', self::ALL_ABILITIES)
            ->plainTextToken;

        $this->sweepApiRoutes(function (string $method, string $path) use ($token): void {
            $this->withToken($token)->json($method, $path)->assertForbidden();
        });
    }

    /**
     * @param  callable(string, string): void  $assertRejected
     */
    private function sweepApiRoutes(callable $assertRejected): void
    {
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $assertRejected($method, $this->examplePath($route));
                $this->app['auth']->forgetGuards();
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    private function examplePath(Route $route): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
    }
}
