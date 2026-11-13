<?php

namespace Tests\Feature\Admin;

use App\Actions\ApiTokens\MintApiToken;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Models\Boat;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'taylor@example.test']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.api-tokens.index'))->assertRedirect(route('login'));
    }

    public function test_non_admins_are_forbidden_even_on_password_protected_routes(): void
    {
        $user = User::factory()->create();
        $token = PersonalAccessToken::factory()->create();

        $this->actingAs($user)->get(route('admin.api-tokens.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.api-tokens.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.api-tokens.store'), $this->storePayload())->assertForbidden();
        $this->actingAs($user)->delete(route('admin.api-tokens.destroy', $token))->assertForbidden();

        $this->assertModelExists($token);
    }

    public function test_inactive_admins_are_logged_out(): void
    {
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);

        $this->actingAs($inactiveAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.api-tokens.store'), $this->storePayload(['owner_id' => $this->admin->id]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    public function test_the_app_model_backs_sanctum(): void
    {
        $this->assertSame(PersonalAccessToken::class, Sanctum::$personalAccessTokenModel);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'create' => ['get', 'admin.api-tokens.create'],
            'store' => ['post', 'admin.api-tokens.store'],
            'edit' => ['get', 'admin.api-tokens.edit'],
            'update' => ['put', 'admin.api-tokens.update'],
            'rotate' => ['post', 'admin.api-tokens.rotate'],
            'destroy' => ['delete', 'admin.api-tokens.destroy'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_write_routes_require_a_recent_password_confirmation(string $method, string $routeName): void
    {
        $token = $this->mintFor($this->admin)->accessToken;
        $parameters = str_contains($routeName, 'create') || str_contains($routeName, 'store') ? [] : [$token];

        $this->actingAs($this->admin)
            ->{$method}(route($routeName, $parameters), $this->storePayload(['name' => 'other']))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time() - 901])
            ->{$method}(route($routeName, $parameters), $this->storePayload(['name' => 'other']))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->assertModelExists($token);
        $this->assertSame('agent', $token->fresh()->name);
    }

    public function test_index_and_show_do_not_require_confirmation_and_carry_no_forms(): void
    {
        $token = $this->mintFor($this->admin)->accessToken;

        $tokenFormAction = 'action="'.route('admin.api-tokens.index');

        $this->actingAs($this->admin)->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->assertSee('API tokens')
            ->assertSee(route('admin.api-tokens.edit', $token), false)
            ->assertDontSee($tokenFormAction, false);

        $this->actingAs($this->admin)->get(route('admin.api-tokens.show', $token))
            ->assertOk()
            ->assertDontSee($tokenFormAction, false);
    }

    public function test_index_shows_an_empty_state(): void
    {
        $this->actingAs($this->admin)->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->assertSee('No API tokens yet.');
    }

    public function test_confirm_password_is_throttled_and_a_correct_password_still_confirms(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->actingAs($this->admin)->post('/confirm-password', ['password' => 'wrong'])->assertSessionHasErrors('password');
        }

        $this->actingAs($this->admin)->post('/confirm-password', ['password' => 'password'])->assertTooManyRequests();

        $this->travel(61)->seconds();

        $this->actingAs($this->admin)->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_store_mints_a_hashed_token_and_reveals_it_exactly_once(): void
    {
        $this->freezeSecond();
        $agent = User::factory()->admin()->create(['email' => 'agent@example.test']);
        Log::spy();

        $response = $this->confirmed()->post(route('admin.api-tokens.store'), $this->storePayload([
            'name' => 'parser-sweep',
            'owner_id' => $agent->id,
            'abilities' => ['read', 'aliases', 'entities', 'reparse'],
            'expires_in_days' => 30,
        ]));

        $token = PersonalAccessToken::query()->sole();
        $response->assertRedirect(route('admin.api-tokens.show', $token))->assertSessionHas('status', 'Token created.');

        $this->assertTrue($token->tokenable->is($agent));
        $this->assertSame(['read', 'aliases', 'entities', 'reparse'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(30)));
        $this->assertSame($this->admin->id, $token->created_by_user_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}$/', $token->token_hint);

        $reveal = session(ApiTokenController::REVEAL_SESSION_KEY);
        $plaintext = Crypt::decryptString($reveal['ciphertext']);
        $this->assertNotSame($plaintext, $reveal['ciphertext']);
        $this->assertStringNotContainsString(explode('|', $plaintext)[1], json_encode(session()->all()));
        $this->assertSame(hash('sha256', explode('|', $plaintext)[1]), $token->token);
        $this->assertSame(substr($plaintext, -4), $token->token_hint);

        $show = $this->confirmed()->get(route('admin.api-tokens.show', $token));
        $show->assertOk()->assertSee($plaintext)->assertSee('Copy your new token now');
        $this->assertStringContainsString('no-store', $show->headers->get('Cache-Control'));
        $show->assertSee('/pass:&lt;token&gt;', false)->assertDontSee('/pass:'.$plaintext, false);

        $this->confirmed()->get(route('admin.api-tokens.show', $token))
            ->assertOk()
            ->assertDontSee($plaintext)
            ->assertDontSee('Copy your new token now');

        $this->withToken($plaintext)->getJson('/api/parser-errors')->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'api-token.created'
            && $context['actor_user_id'] === $this->admin->id
            && $context['actor_email'] === 'taylor@example.test'
            && $context['token_id'] === $token->id
            && $context['owner_user_id'] === $agent->id
            && ! str_contains(json_encode($context), $token->token_hint)
            && ! str_contains(json_encode($context), $token->token))->once();
        Log::shouldNotHaveReceived('info', fn (string $message, array $context = []): bool => str_contains($message.json_encode($context), explode('|', $plaintext)[1]));
    }

    public function test_show_without_the_flash_or_for_another_token_or_after_the_window_reveals_nothing(): void
    {
        $first = $this->mintFor($this->admin, 'first');
        $second = $this->mintFor($this->admin, 'second');

        $this->confirmed()->get(route('admin.api-tokens.show', $first->accessToken))
            ->assertOk()
            ->assertDontSee($first->plainTextToken);

        $this->confirmed()
            ->withSession([ApiTokenController::REVEAL_SESSION_KEY => $this->reveal($first)])
            ->get(route('admin.api-tokens.show', $second->accessToken))
            ->assertDontSee($first->plainTextToken)
            ->assertDontSee($second->plainTextToken);

        $stale = $this->reveal($second, now()->getTimestamp() - ApiTokenController::REVEAL_WINDOW_SECONDS - 1);
        $this->confirmed()
            ->withSession([ApiTokenController::REVEAL_SESSION_KEY => $stale])
            ->get(route('admin.api-tokens.show', $second->accessToken))
            ->assertDontSee($second->plainTextToken);
    }

    public function test_pages_show_only_the_fixed_width_masked_hint_after_the_reveal(): void
    {
        $first = $this->mintFor($this->admin, 'first');
        $tokens = [$first, $this->mintFor($this->admin, 'second')];

        foreach ($tokens as $newToken) {
            $model = $newToken->accessToken;
            $secret = substr(explode('|', $newToken->plainTextToken)[1], strlen(config('sanctum.token_prefix')));

            foreach ([
                $this->actingAs($this->admin)->get(route('admin.api-tokens.index')),
                $this->actingAs($this->admin)->get(route('admin.api-tokens.show', $model)),
                $this->confirmed()->get(route('admin.api-tokens.edit', $model)),
            ] as $response) {
                $response->assertOk()
                    ->assertSee($model->maskedToken())
                    ->assertSee('<span class="sr-only">Token ending in '.$model->token_hint.'</span>', false);
                $this->assertNoSecretFragment($response, $secret, $model);
            }
        }

        $secretLength = strlen(explode('|', $first->plainTextToken)[1]);
        $this->assertSame(PersonalAccessToken::MASK_LENGTH, substr_count($first->accessToken->maskedToken(), '•'));
        $this->assertNotSame($secretLength - strlen(config('sanctum.token_prefix')) - 4, PersonalAccessToken::MASK_LENGTH);
        $this->assertSame('fishcounts_'.str_repeat('•', 12).$first->accessToken->token_hint, $first->accessToken->maskedToken());
    }

    public function test_a_token_without_a_hint_renders_the_no_hint_form(): void
    {
        $token = PersonalAccessToken::factory()->forTokenable($this->admin)->create();

        $this->actingAs($this->admin)->get(route('admin.api-tokens.show', $token))
            ->assertOk()
            ->assertSee($token->maskedPrefix())
            ->assertSee('(no hint recorded)');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidStorePayloads(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'no abilities' => [['abilities' => []], 'abilities'],
            'unknown ability' => [['abilities' => ['read', 'root']], 'abilities.1'],
            'duplicate ability' => [['abilities' => ['read', 'read']], 'abilities.0'],
            'expiry not offered' => [['expires_in_days' => 45], 'expires_in_days'],
            'expiry too long' => [['expires_in_days' => 3650], 'expires_in_days'],
            'missing expiry' => [['expires_in_days' => null], 'expires_in_days'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidStorePayloads')]
    public function test_store_rejects_invalid_input(array $overrides, string $errorKey): void
    {
        $this->confirmed()
            ->from(route('admin.api-tokens.create'))
            ->post(route('admin.api-tokens.store'), $this->storePayload($overrides))
            ->assertSessionHasErrors($errorKey);

        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    public function test_store_rejects_owners_that_are_not_active_admins(): void
    {
        foreach ([User::factory()->create(), User::factory()->admin()->create(['is_active' => false])] as $owner) {
            $this->confirmed()
                ->post(route('admin.api-tokens.store'), $this->storePayload(['owner_id' => $owner->id]))
                ->assertSessionHasErrors('owner_id');
        }

        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    public function test_names_are_unique_per_owner_only(): void
    {
        $agent = User::factory()->admin()->create();
        $this->mintFor($this->admin, 'agent');

        $this->confirmed()->post(route('admin.api-tokens.store'), $this->storePayload(['name' => 'agent']))
            ->assertSessionHasErrors('name');

        $this->confirmed()->post(route('admin.api-tokens.store'), $this->storePayload(['name' => 'agent', 'owner_id' => $agent->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_posted_creator_and_hint_are_ignored(): void
    {
        $other = User::factory()->admin()->create();

        $this->confirmed()->post(route('admin.api-tokens.store'), $this->storePayload([
            'created_by_user_id' => $other->id,
            'token_hint' => 'zzzz',
            'token' => 'forged',
        ]));

        $token = PersonalAccessToken::query()->sole();
        $this->assertSame($this->admin->id, $token->created_by_user_id);
        $this->assertNotSame('zzzz', $token->token_hint);
        $this->assertNotSame('forged', $token->token);
    }

    public function test_update_renames_only(): void
    {
        $newToken = $this->mintFor($this->admin, 'old-name');
        $token = $newToken->accessToken;
        $expiresAt = $token->expires_at;
        Log::spy();

        $this->confirmed()->put(route('admin.api-tokens.update', $token), [
            'name' => 'new-name',
            'abilities' => ['read', 'entities'],
            'expires_in_days' => 365,
            'owner_id' => User::factory()->admin()->create()->id,
        ])->assertRedirect(route('admin.api-tokens.edit', $token))->assertSessionHas('status', 'Token renamed.');

        $token->refresh();
        $this->assertSame('new-name', $token->name);
        $this->assertSame(['read'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo($expiresAt));
        $this->assertTrue($token->tokenable->is($this->admin));
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'api-token.renamed'
            && $context['previous_name'] === 'old-name')->once();
    }

    public function test_update_rejects_a_name_another_token_of_the_owner_has(): void
    {
        $this->mintFor($this->admin, 'taken');
        $token = $this->mintFor($this->admin, 'mine')->accessToken;

        $this->confirmed()->put(route('admin.api-tokens.update', $token), ['name' => 'taken'])->assertSessionHasErrors('name');
        $this->confirmed()->put(route('admin.api-tokens.update', $token), ['name' => 'mine'])->assertSessionHasNoErrors();
    }

    public function test_rotate_replaces_the_secret_and_kills_the_old_token(): void
    {
        $agent = User::factory()->admin()->create();
        $old = $this->mintFor($agent, 'parser-sweep', ['read']);
        Log::spy();

        $this->withToken($old->plainTextToken)->getJson('/api/parser-errors')->assertOk();
        $this->app['auth']->forgetGuards();

        $response = $this->confirmed()->post(route('admin.api-tokens.rotate', $old->accessToken), [
            'abilities' => ['read', 'aliases'],
            'expires_in_days' => 7,
        ]);

        $new = PersonalAccessToken::query()->sole();
        $response->assertRedirect(route('admin.api-tokens.show', $new));
        $this->assertModelMissing($old->accessToken);
        $this->assertSame('parser-sweep', $new->name);
        $this->assertTrue($new->tokenable->is($agent));
        $this->assertSame(['read', 'aliases'], $new->abilities);
        $this->assertSame($this->admin->id, $new->created_by_user_id);

        $plaintext = Crypt::decryptString(session(ApiTokenController::REVEAL_SESSION_KEY)['ciphertext']);
        $this->assertSame(substr($plaintext, -4), $new->token_hint);

        $this->withToken($old->plainTextToken)->getJson('/api/parser-errors')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($plaintext)->getJson('/api/parser-errors')->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'api-token.rotated'
            && $context['token_id'] === $new->id
            && $context['previous_token_id'] === $old->accessToken->id
            && $context['previous_abilities'] === ['read']
            && $context['abilities'] === ['read', 'aliases']
            && $context['previous_expires_at'] === $old->accessToken->expires_at->toIso8601String()
            && $context['expires_at'] === $new->expires_at->toIso8601String())->once();
    }

    public function test_rotating_a_token_that_is_already_gone_creates_nothing(): void
    {
        $token = $this->mintFor($this->admin)->accessToken;
        $tokenId = $token->id;
        $token->delete();

        $this->confirmed()->post(route('admin.api-tokens.rotate', $tokenId), [
            'abilities' => ['read'],
            'expires_in_days' => 30,
        ])->assertNotFound();

        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    public function test_a_failure_inside_rotation_leaves_the_old_token_working(): void
    {
        $old = $this->mintFor($this->admin);
        $this->mock(MintApiToken::class)
            ->shouldReceive('handle')
            ->andReturnUsing(function (User $owner, string $name): never {
                $owner->createToken($name.'-half-written', ['read']);

                throw new \RuntimeException('Mint failed after writing.');
            });
        $this->withoutExceptionHandling();

        try {
            $this->confirmed()->post(route('admin.api-tokens.rotate', $old->accessToken), ['abilities' => ['read'], 'expires_in_days' => 30]);
            $this->fail('Rotation should have thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mint failed after writing.', $exception->getMessage());
        }

        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->assertModelExists($old->accessToken);
        $this->withToken($old->plainTextToken)->getJson('/api/parser-errors')->assertOk();
    }

    public function test_rotate_with_an_expired_confirmation_changes_nothing(): void
    {
        $old = $this->mintFor($this->admin);

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time() - 901])
            ->post(route('admin.api-tokens.rotate', $old->accessToken), ['abilities' => ['read'], 'expires_in_days' => 30])
            ->assertRedirect(route('password.confirm'));

        $this->assertModelExists($old->accessToken);
        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_destroy_revokes_immediately(): void
    {
        $newToken = $this->mintFor($this->admin);
        Log::spy();

        $this->withToken($newToken->plainTextToken)->getJson('/api/parser-errors')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->confirmed()->delete(route('admin.api-tokens.destroy', $newToken->accessToken))
            ->assertRedirect(route('admin.api-tokens.index'))
            ->assertSessionHas('status', 'Token revoked.');

        $this->assertModelMissing($newToken->accessToken);
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken->plainTextToken)->getJson('/api/parser-errors')->assertUnauthorized();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => $message === 'api-token.revoked')->once();
    }

    public function test_tokens_not_owned_by_a_user_are_out_of_reach(): void
    {
        $token = PersonalAccessToken::factory()->forTokenable(Boat::factory()->create())->create();

        $this->actingAs($this->admin)->get(route('admin.api-tokens.show', $token))->assertForbidden();
        $this->confirmed()->get(route('admin.api-tokens.edit', $token))->assertForbidden();
        $this->confirmed()->put(route('admin.api-tokens.update', $token), ['name' => 'x'])->assertForbidden();
        $this->confirmed()->delete(route('admin.api-tokens.destroy', $token))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.api-tokens.index'))->assertDontSee($token->name);

        $this->assertModelExists($token);
    }

    public function test_minted_tokens_respect_their_abilities(): void
    {
        $plaintext = $this->mintFor($this->admin, 'aliases-only', ['aliases'])->plainTextToken;

        $this->withToken($plaintext)->getJson('/api/parser-errors')->assertForbidden();
    }

    public function test_expired_tokens_are_listed_as_expired(): void
    {
        PersonalAccessToken::factory()->forTokenable($this->admin)->expired()->create(['name' => 'stale']);

        $this->actingAs($this->admin)->get(route('admin.api-tokens.index'))
            ->assertSee('stale')
            ->assertSee('Expired');
    }

    public function test_cli_minted_tokens_have_a_hint_and_no_creator(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('fish:mint-api-token', ['email' => 'taylor@example.test', '--ability' => ['read']]));

        $token = PersonalAccessToken::query()->sole();
        $this->assertNull($token->created_by_user_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}$/', $token->token_hint);
        $this->assertStringEndsWith($token->token_hint, trim(Artisan::output()));

        $this->actingAs($this->admin)->get(route('admin.api-tokens.show', $token))->assertSee('CLI or deleted user');
    }

    public function test_deleting_the_creator_keeps_the_token_and_clears_the_creator(): void
    {
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', ['created_by_user_id', 'token_hint']));

        $creator = User::factory()->admin()->create();
        $token = PersonalAccessToken::factory()->forTokenable($this->admin)->createdBy($creator)->create();

        $creator->delete();

        $this->assertModelExists($token);
        $this->assertNull($token->fresh()->created_by_user_id);
    }

    public function test_admin_navigation_links_to_api_tokens(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertSee(route('admin.api-tokens.index'), false);
    }

    private function confirmed(): static
    {
        return $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storePayload(array $overrides = []): array
    {
        return [
            'name' => 'agent',
            'owner_id' => $this->admin->id,
            'abilities' => ['read'],
            'expires_in_days' => 90,
            ...$overrides,
        ];
    }

    /**
     * @param  list<string>  $abilities
     */
    private function mintFor(User $owner, string $name = 'agent', array $abilities = ['read']): NewAccessToken
    {
        return app(MintApiToken::class)->handle($owner, $name, $abilities, now()->addDays(30), $this->admin);
    }

    /**
     * @return array{token_id: int, ciphertext: string, minted_at: int}
     */
    private function reveal(NewAccessToken $newToken, ?int $mintedAt = null): array
    {
        return [
            'token_id' => $newToken->accessToken->getKey(),
            'ciphertext' => Crypt::encryptString($newToken->plainTextToken),
            'minted_at' => $mintedAt ?? now()->getTimestamp(),
        ];
    }

    /**
     * The page may carry the four-character hint but no longer run of the secret, and never the hash.
     */
    private function assertNoSecretFragment(TestResponse $response, string $secret, PersonalAccessToken $token): void
    {
        $html = $response->getContent();
        $this->assertStringNotContainsString(PersonalAccessToken::query()->findOrFail($token->id)->token, $html);

        for ($offset = 0; $offset + 5 <= strlen($secret); $offset++) {
            $this->assertStringNotContainsString(substr($secret, $offset, 5), $html, 'A five-character run of the secret reached the page.');
        }
    }
}
