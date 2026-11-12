<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\RawScrapePayload;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression tests for the findings of the independent agent API security pass (#71).
 */
class ApiSecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_route_rejects_a_token_with_no_abilities(): void
    {
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        $token = User::factory()->admin()->create()->createToken('agent', [])->plainTextToken;
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $path = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
                $this->withToken($token)->json($method, $path)->assertForbidden();
                $this->app['auth']->forgetGuards();
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
        Queue::assertNothingPushed();
    }

    public function test_removing_the_admin_role_revokes_tokens_so_re_promotion_does_not_revive_them(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->admin()->create();
        $token = $agent->createToken('agent', ['read'])->plainTextToken;

        $this->actingAs($admin)->put(route('admin.users.update', $agent), [
            'name' => $agent->name,
            'email' => $agent->email,
            'role' => Role::User->value,
            'timezone' => 'America/Los_Angeles',
            'is_active' => '1',
        ])->assertRedirect();
        $this->assertSame(0, $agent->tokens()->count());

        $this->actingAs($admin)->put(route('admin.users.update', $agent), [
            'name' => $agent->name,
            'email' => $agent->email,
            'role' => Role::Admin->value,
            'timezone' => 'America/Los_Angeles',
            'is_active' => '1',
        ])->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/parser-errors')->assertUnauthorized();
    }

    public function test_editing_an_active_admin_keeps_their_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->admin()->create();
        $token = $agent->createToken('agent', ['read'])->plainTextToken;

        $this->actingAs($admin)->put(route('admin.users.update', $agent), [
            'name' => 'Renamed agent',
            'email' => $agent->email,
            'role' => Role::Admin->value,
            'timezone' => 'America/Los_Angeles',
            'is_active' => '1',
        ])->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/parser-errors')->assertOk();
    }

    public function test_deleting_your_own_account_revokes_your_tokens(): void
    {
        $agent = User::factory()->admin()->create();
        $agent->createToken('agent', ['read']);

        $this->actingAs($agent)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_token_created_without_an_expiry_still_expires_after_365_days(): void
    {
        $token = User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;

        $this->travel(364)->days();
        $this->withToken($token)->getJson('/api/parser-errors')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travel(2)->days();
        $this->withToken($token)->getJson('/api/parser-errors')->assertUnauthorized();
    }

    public function test_expired_tokens_are_pruned_daily(): void
    {
        $prune = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'sanctum:prune-expired'));

        $this->assertNotNull($prune);
        $this->assertSame('30 3 * * *', $prune->expression);
    }

    public function test_body_redacts_secrets_in_quoted_json_and_script_keys(): void
    {
        $body = '{"password": "hunter2", "api_key":"sk-live-1", "access_token" : \'tok-2\', '
            .'"Authorization": "Bearer abc.def", "client_secret":"cs-3", "refresh_token":"rt-4", '
            .'"boat": "Dolphin"}';
        $payload = RawScrapePayload::factory()->create(['payload' => $body]);

        $response = $this->withToken($this->readToken())
            ->getJson("/api/raw-payloads/{$payload->id}/body")
            ->assertOk();

        foreach (['hunter2', 'sk-live-1', 'tok-2', 'abc.def', 'cs-3', 'rt-4'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertStringContainsString('"password"=[redacted]', $response->json('data.body'));
        $this->assertStringContainsString('"boat": "Dolphin"', $response->json('data.body'));
    }

    public function test_body_that_is_not_valid_utf8_is_returned_redacted_instead_of_failing(): void
    {
        $payload = RawScrapePayload::factory()->create(['payload' => "<p>Caf\xE9 password=hunter2 </p><p>The Dolphin caught 17 Yellowtail.</p>"]);

        $response = $this->withToken($this->readToken())
            ->getJson("/api/raw-payloads/{$payload->id}/body")
            ->assertOk();

        $this->assertStringNotContainsString('hunter2', $response->getContent());
        $this->assertStringContainsString('The Dolphin caught 17 Yellowtail.', $response->json('data.body'));
    }

    private function readToken(): string
    {
        return User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
    }
}
