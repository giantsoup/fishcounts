<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Region;
use App\Models\Species;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertRuleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_alert_rule(): void
    {
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);

        $response = $this->actingAs($user)->post(route('alert-rules.store'), [
            'name' => 'Local Yellowtail',
            'species_id' => $species->id,
            'minimum_score' => 70,
            'trend_window_days' => 3,
            'baseline_window_days' => 7,
            'region_ids' => [$region->id],
            'trip_type_ids' => [$tripType->id],
            'is_enabled' => '1',
            'email_enabled' => '1',
            'include_in_weekly_digest' => '1',
        ]);

        $response->assertRedirect();
        $rule = AlertRule::query()->firstOrFail();
        $this->assertTrue($rule->regions()->whereKey($region)->exists());
        $this->assertTrue($rule->tripTypes()->whereKey($tripType)->exists());
    }

    public function test_user_cannot_edit_another_users_alert_rule(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $rule = AlertRule::query()->create([
            'user_id' => $owner->id,
            'species_id' => $species->id,
            'name' => 'Owner rule',
        ]);

        $this->actingAs($other)->get(route('alert-rules.edit', $rule))->assertForbidden();
    }
}
