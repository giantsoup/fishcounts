<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user_and_send_invitation(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Deckhand User',
            'email' => 'deckhand@example.test',
            'role' => Role::User->value,
            'timezone' => 'America/Los_Angeles',
            'is_active' => '1',
        ]);

        $user = User::query()->where('email', 'deckhand@example.test')->firstOrFail();

        $response
            ->assertRedirect(route('admin.users.edit', $user))
            ->assertSessionHas('status', 'User created and invitation email sent.');

        $this->assertSame('Deckhand User', $user->name);
        $this->assertSame(Role::User, $user->role);
        $this->assertTrue($user->is_active);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_normal_user_cannot_create_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.users.store'), [
            'name' => 'Blocked User',
            'email' => 'blocked@example.test',
            'role' => Role::User->value,
            'timezone' => 'America/Los_Angeles',
            'is_active' => '1',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => Role::Admin->value,
            'timezone' => 'America/Los_Angeles',
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_admin_cannot_remove_own_admin_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => Role::User->value,
            'timezone' => 'America/Los_Angeles',
            'is_active' => '1',
        ])->assertSessionHasErrors('role');

        $this->assertSame(Role::Admin, $admin->refresh()->role);
    }

    public function test_admin_can_deactivate_another_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => Role::User->value,
            'timezone' => 'America/Los_Angeles',
        ])->assertRedirect(route('admin.users.edit', $user));

        $this->assertFalse($user->refresh()->is_active);
    }
}
