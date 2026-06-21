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

    public function test_alert_rule_form_includes_required_baseline_window_field(): void
    {
        $user = User::factory()->create();
        Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);

        $this->actingAs($user)
            ->get(route('alert-rules.create'))
            ->assertOk()
            ->assertSee('name="baseline_window_days"', false);
    }

    public function test_alert_rule_form_renders_enhanced_multi_selects_without_fixed_heights(): void
    {
        $user = User::factory()->create();
        Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);

        $this->actingAs($user)
            ->get(route('alert-rules.create'))
            ->assertOk()
            ->assertSee('name="region_ids[]"', false)
            ->assertSee('name="trip_type_ids[]"', false)
            ->assertSee('name="landing_ids[]"', false)
            ->assertSee('name="boat_ids[]"', false)
            ->assertSee('data-select-mode="multiple"', false)
            ->assertDontSee('min-h-28', false);
    }

    public function test_user_can_disable_alert_rule_boolean_flags(): void
    {
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'species_id' => $species->id,
            'name' => 'Owner rule',
            'is_enabled' => true,
            'email_enabled' => true,
            'discord_enabled' => true,
            'include_in_weekly_digest' => true,
        ]);
        $rule->regions()->sync([$region->id]);
        $rule->tripTypes()->sync([$tripType->id]);

        $this->actingAs($user)->put(route('alert-rules.update', $rule), [
            'name' => 'Owner rule',
            'species_id' => $species->id,
            'minimum_score' => 70,
            'trend_window_days' => 3,
            'baseline_window_days' => 7,
            'region_ids' => [$region->id],
            'trip_type_ids' => [$tripType->id],
            'is_enabled' => '0',
            'email_enabled' => '0',
            'discord_enabled' => '0',
            'include_in_weekly_digest' => '0',
        ])->assertRedirect(route('alert-rules.edit', $rule));

        $rule->refresh();

        $this->assertFalse($rule->is_enabled);
        $this->assertFalse($rule->email_enabled);
        $this->assertFalse($rule->discord_enabled);
        $this->assertFalse($rule->include_in_weekly_digest);
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
