<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_without_token_gets_json_401(): void
    {
        $this->getJson('/api/parser-errors')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_request_without_json_accept_header_still_gets_json_401(): void
    {
        $this->get('/api/parser-errors')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_token_gets_json_401(): void
    {
        $this->withToken('1|not-a-real-token')
            ->getJson('/api/parser-errors')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_expired_token_gets_json_401(): void
    {
        $token = User::factory()->admin()->create()
            ->createToken('agent', ['read'], now()->addDay())
            ->plainTextToken;

        $this->travel(2)->days();

        $this->withToken($token)
            ->getJson('/api/parser-errors')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_token_without_required_ability_gets_json_403(): void
    {
        $token = User::factory()->admin()->create()->createToken('agent', ['aliases'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/parser-errors')
            ->assertForbidden()
            ->assertJsonPath('message', 'Invalid ability provided.');
    }

    public function test_token_for_inactive_user_gets_json_403(): void
    {
        $token = User::factory()->admin()->create(['is_active' => false])
            ->createToken('agent', ['read'])
            ->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/parser-errors')
            ->assertForbidden()
            ->assertExactJson(['message' => 'This account is inactive.']);
    }

    public function test_token_for_non_admin_user_gets_json_403(): void
    {
        $token = User::factory()->create()->createToken('agent', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/parser-errors')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_api_ignores_browser_session_login(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/parser-errors')
            ->assertUnauthorized();
    }
}
