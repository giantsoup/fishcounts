<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.backfills.index'));
    }

    public function test_normal_user_cannot_create_reference_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.species.store'), ['name' => 'Soupfin Shark'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.trip-types.store'), ['name' => '3.5 Day'])
            ->assertForbidden();
    }
}
