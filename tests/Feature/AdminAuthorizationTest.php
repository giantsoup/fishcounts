<?php

namespace Tests\Feature;

use App\Models\Boat;
use App\Models\TripType;
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

        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);

        $this->actingAs($user)
            ->post(route('admin.boats.store'), ['boat_name' => 'Pacific Queen'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.boat-aliases.store'), ['boat_id' => $boat->id, 'alias' => 'The Dolphin'])
            ->assertForbidden();
    }

    public function test_normal_user_cannot_update_trip_type_order(): void
    {
        $user = User::factory()->create();
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day', 'sort_order' => 6]);

        $this->actingAs($user)
            ->patch(route('admin.trip-types.update', $tripType), ['order_sort_order' => 1])
            ->assertForbidden();
    }
}
