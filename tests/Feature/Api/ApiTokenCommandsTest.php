<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiTokenCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $lastOutput = '';

    public function test_mint_prints_a_working_token_once_with_requested_abilities_and_default_expiry(): void
    {
        $this->freezeSecond();
        $agent = User::factory()->admin()->create(['email' => 'agent@example.test']);

        $token = $this->mint(['email' => 'agent@example.test', '--name' => 'parser-sweep', '--ability' => ['read', 'aliases']]);

        $this->assertSame(1, substr_count($this->lastOutput, $token));
        $stored = $agent->tokens()->sole();
        $this->assertSame('parser-sweep', $stored->name);
        $this->assertSame(['read', 'aliases'], $stored->abilities);
        $this->assertTrue($stored->expires_at->equalTo(now()->addDays(90)));

        $this->withToken($token)->getJson('/api/parser-errors')->assertOk();
    }

    public function test_mint_accepts_a_custom_expiry_and_the_token_stops_working_after_it(): void
    {
        User::factory()->admin()->create(['email' => 'agent@example.test']);

        $token = $this->mint(['email' => 'agent@example.test', '--ability' => ['read'], '--days' => 7]);

        $this->travel(8)->days();
        $this->withToken($token)->getJson('/api/parser-errors')->assertUnauthorized();
    }

    public function test_minted_token_lacks_abilities_it_was_not_given(): void
    {
        User::factory()->admin()->create(['email' => 'agent@example.test']);

        $token = $this->mint(['email' => 'agent@example.test', '--ability' => ['aliases']]);

        $this->withToken($token)->getJson('/api/parser-errors')->assertForbidden();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function rejectedMintOptions(): array
    {
        return [
            'unknown user' => [['email' => 'nobody@example.test', '--ability' => ['read']]],
            'no ability' => [['email' => 'agent@example.test']],
            'unknown ability' => [['email' => 'agent@example.test', '--ability' => ['read', 'superuser']]],
            'wildcard ability' => [['email' => 'agent@example.test', '--ability' => ['*']]],
            'zero days' => [['email' => 'agent@example.test', '--ability' => ['read'], '--days' => 0]],
            'non-numeric days' => [['email' => 'agent@example.test', '--ability' => ['read'], '--days' => 'soon']],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('rejectedMintOptions')]
    public function test_mint_rejects_invalid_options_without_creating_a_token(array $options): void
    {
        User::factory()->admin()->create(['email' => 'agent@example.test']);

        $this->assertSame(Command::FAILURE, Artisan::call('fish:mint-api-token', $options));
        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    public function test_mint_refuses_non_admin_and_inactive_users(): void
    {
        User::factory()->create(['email' => 'user@example.test']);
        User::factory()->admin()->create(['email' => 'inactive@example.test', 'is_active' => false]);

        $this->assertSame(Command::FAILURE, Artisan::call('fish:mint-api-token', ['email' => 'user@example.test', '--ability' => ['read']]));
        $this->assertSame(Command::FAILURE, Artisan::call('fish:mint-api-token', ['email' => 'inactive@example.test', '--ability' => ['read']]));
        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    public function test_revoke_by_name_removes_only_that_token(): void
    {
        User::factory()->admin()->create(['email' => 'agent@example.test']);
        $revoked = $this->mint(['email' => 'agent@example.test', '--name' => 'old', '--ability' => ['read']]);
        $kept = $this->mint(['email' => 'agent@example.test', '--name' => 'new', '--ability' => ['read']]);

        $this->assertSame(Command::SUCCESS, Artisan::call('fish:revoke-api-tokens', ['email' => 'agent@example.test', '--name' => 'old']));

        $this->withToken($revoked)->getJson('/api/parser-errors')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($kept)->getJson('/api/parser-errors')->assertOk();
    }

    public function test_revoke_all_removes_every_token_for_the_user(): void
    {
        $agent = User::factory()->admin()->create(['email' => 'agent@example.test']);
        $other = User::factory()->admin()->create();
        $other->createToken('other', ['read']);
        $first = $this->mint(['email' => 'agent@example.test', '--name' => 'one', '--ability' => ['read']]);
        $this->mint(['email' => 'agent@example.test', '--name' => 'two', '--ability' => ['read']]);

        $this->assertSame(Command::SUCCESS, Artisan::call('fish:revoke-api-tokens', ['email' => 'agent@example.test', '--all' => true]));

        $this->assertSame(0, $agent->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->withToken($first)->getJson('/api/parser-errors')->assertUnauthorized();
    }

    public function test_revoke_requires_a_name_or_all(): void
    {
        $agent = User::factory()->admin()->create(['email' => 'agent@example.test']);
        $agent->createToken('agent', ['read']);

        $this->assertSame(Command::FAILURE, Artisan::call('fish:revoke-api-tokens', ['email' => 'agent@example.test']));
        $this->assertSame(Command::FAILURE, Artisan::call('fish:revoke-api-tokens', ['email' => 'agent@example.test', '--name' => 'missing']));
        $this->assertSame(1, $agent->tokens()->count());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function mint(array $options): string
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('fish:mint-api-token', $options));
        $this->lastOutput = Artisan::output();
        $this->assertSame(1, preg_match('/\d+\|fishcounts_\w+/', $this->lastOutput, $matches));

        return $matches[0];
    }
}
