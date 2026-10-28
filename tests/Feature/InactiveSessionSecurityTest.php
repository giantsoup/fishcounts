<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InactiveSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('accountRoles')]
    public function test_deactivated_accounts_lose_existing_session_access(bool $administrator): void
    {
        $user = $administrator ? User::factory()->admin()->create() : User::factory()->create();
        $sessionKey = Auth::guard('web')->getName();
        $user->update(['is_active' => false]);

        $this->withSession([$sessionKey => $user->id, 'private_marker' => 'audit-session'])
            ->get(route($administrator ? 'admin.dashboard' : 'profile.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('private_marker');

        $this->assertGuest('web');
    }

    public function test_deactivated_session_cannot_change_profile(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        Notification::fake();
        Http::preventStrayRequests();

        $response = $this->withSession([Auth::guard('web')->getName() => $user->id])
            ->patch(route('profile.update'), ['name' => 'Changed by stale session', 'email' => $user->email]);

        $this->assertSame($user->name, $user->fresh()->name);
        $response->assertRedirect(route('login'));
        $this->assertGuest('web');
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_deactivated_administrator_cannot_create_users(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => false]);
        Notification::fake();

        $response = $this->withSession([Auth::guard('web')->getName() => $admin->id])
            ->post(route('admin.users.store'), [
                'name' => 'Blocked audit user',
                'email' => 'blocked-audit@example.test',
                'role' => 'user',
                'timezone' => 'America/Los_Angeles',
                'is_active' => '1',
            ]);

        $this->assertDatabaseCount('users', 1);
        $response->assertRedirect(route('login'));
        $this->assertGuest('web');
        Notification::assertNothingSent();
    }

    #[DataProvider('accountRoles')]
    public function test_deactivated_accounts_cannot_restore_access_with_remember_cookie(bool $administrator): void
    {
        $user = $administrator ? User::factory()->admin()->create() : User::factory()->create();
        $rememberToken = $user->remember_token;
        $recaller = Auth::guard('web')->getRecallerName();
        $user->update(['is_active' => false]);

        $this->withCookie($recaller, $user->id.'|'.$rememberToken.'|'.$user->password)
            ->get(route($administrator ? 'admin.dashboard' : 'profile.edit'))
            ->assertRedirect(route('login'))
            ->assertCookieExpired($recaller);

        $this->assertGuest('web');
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
    }

    public function test_active_session_can_change_own_profile(): void
    {
        $user = User::factory()->create();

        $this->withSession([Auth::guard('web')->getName() => $user->id])
            ->patch(route('profile.update'), ['name' => 'Permitted profile change', 'email' => $user->email])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Permitted profile change', $user->fresh()->name);
        $this->assertAuthenticatedAs($user, 'web');
    }

    #[DataProvider('accountRoles')]
    public function test_active_accounts_can_use_remember_cookie(bool $administrator): void
    {
        $user = $administrator ? User::factory()->admin()->create() : User::factory()->create();

        $this->withCookie(Auth::guard('web')->getRecallerName(), $user->id.'|'.$user->remember_token.'|'.$user->password)
            ->get(route($administrator ? 'admin.dashboard' : 'profile.edit'))
            ->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
    }

    /** @return array<string, array{bool}> */
    public static function accountRoles(): array
    {
        return ['normal account' => [false], 'administrator' => [true]];
    }
}
