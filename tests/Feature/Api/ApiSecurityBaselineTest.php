<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\User;
use App\Support\ApiAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiSecurityBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_minted_tokens_carry_the_secret_scanning_prefix(): void
    {
        $token = User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;

        $this->assertMatchesRegularExpression('/^\d+\|fishcounts_[A-Za-z0-9]{40}/', $token);
        $this->withToken($token)->getJson('/api/parser-errors')->assertOk();
    }

    public function test_api_sends_no_cors_headers(): void
    {
        $token = User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;

        $this->withToken($token)
            ->withHeader('Origin', 'https://evil.example')
            ->getJson('/api/parser-errors')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/parser-errors')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_sanctum_session_cookie_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('sanctum.csrf-cookie'));
    }

    public function test_deactivating_a_user_deletes_their_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->admin()->create();
        $token = $agent->createToken('agent', ['read'])->plainTextToken;

        $this->actingAs($admin)->put(route('admin.users.update', $agent), [
            'name' => $agent->name,
            'email' => $agent->email,
            'role' => Role::Admin->value,
            'timezone' => 'America/Los_Angeles',
        ])->assertRedirect();

        $this->assertFalse($agent->fresh()->is_active);
        $this->assertSame(0, $agent->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/parser-errors')->assertUnauthorized();
    }

    public function test_api_requests_are_rate_limited_per_token(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('agent', ['read'])->plainTextToken;
        $otherToken = $admin->createToken('other', ['read'])->plainTextToken;

        for ($i = 0; $i < 60; $i++) {
            $this->withToken($token)->getJson('/api/parser-errors')->assertOk();
        }

        $this->withToken($token)->getJson('/api/parser-errors')->assertTooManyRequests();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/parser-errors')->assertOk();
    }

    public function test_server_errors_carry_no_debug_detail_and_logs_carry_no_token(): void
    {
        config(['app.debug' => false]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        $token = User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
        Schema::drop('parser_errors');

        $this->withToken($token)
            ->getJson('/api/parser-errors')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Server Error']);

        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(explode('|', $token)[1], implode("\n", $logged));
        $this->assertStringNotContainsString('Bearer', implode("\n", $logged));
    }

    public function test_unknown_api_path_returns_json_404(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/no-such-endpoint')
            ->assertNotFound()
            ->assertExactJson(['message' => 'The route api/no-such-endpoint could not be found.']);
    }

    public function test_api_writes_log_one_structured_line_without_the_token(): void
    {
        Route::middleware(['api', 'agent-api', 'abilities:errors'])
            ->post('api/_audit-probe/{target}', function (Request $request, int $target): JsonResponse {
                ApiAudit::write($request, 'probe.write', $target);

                return response()->json(['ok' => true]);
            });
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });
        $agent = User::factory()->admin()->create();
        $token = $agent->createToken('sweep-agent', ['errors'])->plainTextToken;

        $this->withToken($token)->postJson('/api/_audit-probe/42')->assertOk();

        $this->assertCount(1, $logged);
        $this->assertSame('info', $logged[0]->level);
        $this->assertSame('api.write', $logged[0]->message);
        $this->assertSame([
            'user_id' => $agent->id,
            'token_name' => 'sweep-agent',
            'action' => 'probe.write',
            'target_id' => 42,
        ], $logged[0]->context);
    }
}
