<?php

namespace Tests\Feature\Api;

use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\Region;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->admin()->create();
    }

    public function test_agent_creates_a_boat_with_an_optional_landing(): void
    {
        $landing = $this->landing();

        $this->withToken($this->token(['entities']))
            ->postJson('/api/boats', ['name' => '  Pacific   Queen ', 'landing_id' => $landing->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pacific Queen')
            ->assertJsonPath('data.slug', 'pacific-queen')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.landing.id', $landing->id);

        $this->withToken($this->token(['entities']))
            ->postJson('/api/boats', ['name' => 'Lone Star'])
            ->assertCreated()
            ->assertJsonPath('data.landing', null);

        $this->assertDatabaseHas('boats', ['name' => 'Pacific Queen', 'landing_id' => $landing->id]);
        $this->assertDatabaseHas('boats', ['name' => 'Lone Star', 'landing_id' => null]);
    }

    public function test_creating_a_boat_rejects_duplicate_names_and_aliases_like_the_admin_form(): void
    {
        $token = $this->token(['entities']);
        $existing = Boat::factory()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $existing->aliases()->create(['alias' => 'The Dolphin', 'normalized_alias' => 'the dolphin']);

        $this->withToken($token)->postJson('/api/boats', ['name' => 'DOLPHIN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A boat with this name already exists.']);
        $this->withToken($token)->postJson('/api/boats', ['name' => 'the dolphin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'This name is already an alias for another boat.']);
        $this->withToken($token)->postJson('/api/boats', ['name' => '!!!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->withToken($token)->postJson('/api/boats', ['name' => 'Lone Star', 'landing_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('landing_id');

        $this->assertSame(1, Boat::query()->count());
    }

    public function test_agent_creates_species_and_trip_types(): void
    {
        $token = $this->token(['entities']);

        $this->withToken($token)
            ->postJson('/api/species', ['name' => 'Opaleye'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Opaleye')
            ->assertJsonPath('data.slug', 'opaleye')
            ->assertJsonPath('data.is_active', true);
        $this->withToken($token)
            ->postJson('/api/trip-types', ['name' => '1.5 Day', 'sort_order' => 12])
            ->assertCreated()
            ->assertJsonPath('data.name', '1.5 Day')
            ->assertJsonPath('data.slug', '15-day')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('species', ['name' => 'Opaleye', 'environmental_location_profile' => config('fish.conditions.location_profile')]);
        $this->assertDatabaseHas('trip_types', ['name' => '1.5 Day', 'sort_order' => 12]);
    }

    public function test_creating_species_and_trip_types_rejects_duplicates_like_the_admin_forms(): void
    {
        $token = $this->token(['entities']);
        $species = Species::factory()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $species->aliases()->create(['alias' => 'Yellows', 'normalized_alias' => 'yellows']);
        $tripType = TripType::factory()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        $tripType->aliases()->create(['alias' => 'All Day', 'normalized_alias' => 'all day']);
        $speciesCount = Species::query()->count();
        $tripTypeCount = TripType::query()->count();

        $this->withToken($token)->postJson('/api/species', ['name' => 'yellowtail'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'This species already exists.']);
        $this->withToken($token)->postJson('/api/species', ['name' => 'Yellows'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'This name is already used as a species alias.']);
        $this->withToken($token)->postJson('/api/species', ['name' => 'Opaleye', 'environmental_location_profile' => 'atlantis'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('environmental_location_profile');
        $this->withToken($token)->postJson('/api/trip-types', ['name' => 'Full-Day'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'This trip type already exists.']);
        $this->withToken($token)->postJson('/api/trip-types', ['name' => 'All Day'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'This name is already used as a trip type alias.']);
        $this->withToken($token)->postJson('/api/trip-types', ['name' => 'Twilight', 'sort_order' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort_order');

        $this->assertSame($speciesCount, Species::query()->count());
        $this->assertSame($tripTypeCount, TripType::query()->count());
    }

    public function test_agent_updates_a_boat_and_rejects_a_duplicate_name(): void
    {
        $token = $this->token(['entities']);
        $landing = $this->landing();
        $boat = Boat::factory()->create(['name' => 'Dolphn', 'slug' => 'dolphn']);
        Boat::factory()->create(['name' => 'Pacific Queen', 'slug' => 'pacific-queen']);

        $this->withToken($token)
            ->patchJson("/api/boats/{$boat->id}", [
                'name' => ' Dolphin ',
                'landing_id' => $landing->id,
                'booking_url' => 'https://example.test/dolphin',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dolphin')
            ->assertJsonPath('data.slug', 'dolphin')
            ->assertJsonPath('data.landing.id', $landing->id)
            ->assertJsonPath('data.booking_url', 'https://example.test/dolphin');

        $this->withToken($token)
            ->patchJson("/api/boats/{$boat->id}", ['name' => 'Pacific Queen'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A boat with this name already exists.']);
        $this->withToken($token)
            ->patchJson("/api/boats/{$boat->id}", ['booking_url' => 'not a url', 'is_active' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['booking_url', 'is_active']);

        $this->assertSame('Dolphin', $boat->refresh()->name);
        $this->assertSame('https://example.test/dolphin', $boat->booking_url);
    }

    public function test_agent_updates_species_and_trip_types_and_rejects_duplicate_names(): void
    {
        $token = $this->token(['entities']);
        $species = Species::factory()->create(['name' => 'Yelowtail', 'slug' => 'yelowtail']);
        Species::factory()->create(['name' => 'Bonito', 'slug' => 'bonito']);
        $tripType = TripType::factory()->create(['name' => 'Ful Day', 'slug' => 'ful-day', 'sort_order' => 3]);
        TripType::factory()->create(['name' => 'Half Day AM', 'slug' => 'half-day-am']);

        $this->withToken($token)
            ->patchJson("/api/species/{$species->id}", ['name' => 'Yellowtail'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Yellowtail')
            ->assertJsonPath('data.slug', 'yellowtail');
        $this->withToken($token)
            ->patchJson("/api/trip-types/{$tripType->id}", ['name' => 'Full Day', 'sort_order' => 7])
            ->assertOk()
            ->assertJsonPath('data.name', 'Full Day')
            ->assertJsonPath('data.slug', 'full-day');

        $this->withToken($token)
            ->patchJson("/api/species/{$species->id}", ['name' => 'bonito'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'This species already exists.']);
        $this->withToken($token)
            ->patchJson("/api/trip-types/{$tripType->id}", ['name' => 'Half-Day AM'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'This trip type already exists.']);

        $this->assertSame('Yellowtail', $species->refresh()->name);
        $this->assertSame(7, $tripType->refresh()->sort_order);
        $this->assertSame('Full Day', $tripType->name);
    }

    public function test_deactivation_and_reactivation_go_through_is_active(): void
    {
        $token = $this->token(['entities']);
        $entities = [
            'boats' => Boat::factory()->create(),
            'species' => Species::factory()->create(),
            'trip-types' => TripType::factory()->create(),
        ];

        foreach ($entities as $path => $entity) {
            $this->withToken($token)
                ->patchJson("/api/{$path}/{$entity->id}", ['is_active' => false])
                ->assertOk()
                ->assertJsonPath('data.is_active', false);
            $this->assertFalse($entity->refresh()->is_active);

            $this->withToken($token)
                ->patchJson("/api/{$path}/{$entity->id}", ['is_active' => true])
                ->assertOk()
                ->assertJsonPath('data.is_active', true);
            $this->assertTrue($entity->refresh()->is_active);
        }
    }

    public function test_entity_writes_need_the_entities_ability(): void
    {
        $token = $this->token(['read', 'aliases']);
        $boat = Boat::factory()->create();
        $duplicate = Boat::factory()->create();

        $this->withToken($token)->postJson('/api/boats', ['name' => 'Lone Star'])->assertForbidden();
        $this->withToken($token)->postJson('/api/species', ['name' => 'Opaleye'])->assertForbidden();
        $this->withToken($token)->postJson('/api/trip-types', ['name' => 'Twilight'])->assertForbidden();
        $this->withToken($token)->patchJson("/api/boats/{$boat->id}", ['is_active' => false])->assertForbidden();
        $this->withToken($token)->deleteJson("/api/boats/{$boat->id}")->assertForbidden();
        $this->withToken($token)->postJson("/api/boats/{$boat->id}/merge", ['duplicate_boat_id' => $duplicate->id])->assertForbidden();

        $this->assertTrue($boat->refresh()->is_active);
        $this->assertTrue($duplicate->refresh()->is_active);
        $this->assertDatabaseMissing('boats', ['name' => 'Lone Star']);
    }

    public function test_unreferenced_entities_can_be_deleted(): void
    {
        $token = $this->token(['entities']);
        $entities = [
            'boats' => Boat::factory()->create(),
            'species' => Species::factory()->create(),
            'trip-types' => TripType::factory()->create(),
        ];

        foreach ($entities as $path => $entity) {
            $this->withToken($token)->deleteJson("/api/{$path}/{$entity->id}")->assertNoContent();
            $this->assertModelMissing($entity);
        }
    }

    public function test_deleting_a_referenced_entity_returns_409_and_keeps_it(): void
    {
        $token = $this->token(['entities']);
        $source = ScrapeSource::factory()->create();
        $alertSpecies = Species::factory()->create();
        $report = fn (array $attributes): TripReport => TripReport::query()->create([
            'source_id' => $source->id,
            'trip_date' => '2026-07-10',
            'dedupe_key' => fake()->unique()->uuid(),
            ...$attributes,
        ]);
        $alertRule = fn (array $attributes = []): AlertRule => AlertRule::query()->create([
            'user_id' => $this->agent->id,
            'name' => 'Alert',
            'species_id' => $alertSpecies->id,
            ...$attributes,
        ]);

        $boatWithReport = Boat::factory()->create();
        $report(['boat_id' => $boatWithReport->id]);
        $boatWithAlias = Boat::factory()->create();
        $boatWithAlias->aliases()->create(['alias' => 'Old Name', 'normalized_alias' => 'old name']);
        $boatWithAlert = Boat::factory()->create();
        $alertRule()->boats()->attach($boatWithAlert);

        $speciesWithCount = Species::factory()->create();
        SpeciesCount::query()->create(['trip_report_id' => $report([])->id, 'species_id' => $speciesWithCount->id, 'count' => 4]);
        $speciesWithAlias = Species::factory()->create();
        $speciesWithAlias->aliases()->create(['alias' => 'Yellows', 'normalized_alias' => 'yellows']);
        $speciesWithAlert = Species::factory()->create();
        $alertRule(['species_id' => $speciesWithAlert->id]);

        $tripTypeWithReport = TripType::factory()->create();
        $report(['trip_type_id' => $tripTypeWithReport->id]);
        $tripTypeWithAlias = TripType::factory()->create();
        $tripTypeWithAlias->aliases()->create(['alias' => 'All Day', 'normalized_alias' => 'all day']);
        $tripTypeWithAlert = TripType::factory()->create();
        $alertRule()->tripTypes()->attach($tripTypeWithAlert);

        $referenced = [
            'boats' => [$boatWithReport, $boatWithAlias, $boatWithAlert],
            'species' => [$speciesWithCount, $speciesWithAlias, $speciesWithAlert],
            'trip-types' => [$tripTypeWithReport, $tripTypeWithAlias, $tripTypeWithAlert],
        ];

        foreach ($referenced as $path => $entities) {
            foreach ($entities as $entity) {
                $message = $this->withToken($token)
                    ->deleteJson("/api/{$path}/{$entity->id}")
                    ->assertConflict()
                    ->json('message');

                $this->assertStringContainsString('is_active', $message);
                $this->assertModelExists($entity);
            }
        }

        $this->assertStringContainsString(
            "POST /api/boats/{canonical_id}/merge and {\"duplicate_boat_id\": {$boatWithReport->id}}",
            $this->withToken($token)->deleteJson("/api/boats/{$boatWithReport->id}")->json('message'),
        );
        $this->assertSame(1, $boatWithAlias->aliases()->count());
    }

    public function test_merging_moves_a_duplicate_boats_reports_aliases_and_alert_rules_then_deactivates_it(): void
    {
        $source = ScrapeSource::factory()->create();
        $landing = $this->landing();
        $target = Boat::factory()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $duplicate = Boat::factory()->create(['name' => 'Dolphin II', 'slug' => 'legacy-dolphin-2', 'landing_id' => $landing->id]);
        $duplicate->aliases()->create(['alias' => 'Dolphin 2', 'normalized_alias' => 'dolphin 2']);
        $bystander = Boat::factory()->create(['name' => 'Lone Star', 'slug' => 'lone-star']);
        $report = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => $duplicate->id,
            'trip_date' => '2026-07-09',
            'raw_trip_type' => 'Full Day',
            'anglers' => 20,
            'dedupe_key' => 'duplicate-report',
        ]);
        $bystanderReport = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => $bystander->id,
            'trip_date' => '2026-07-09',
            'dedupe_key' => 'bystander-report',
        ]);
        $alertRule = AlertRule::query()->create([
            'user_id' => $this->agent->id,
            'name' => 'Dolphin alert',
            'species_id' => Species::factory()->create()->id,
        ]);
        $alertRule->boats()->attach($duplicate);

        $this->withToken($this->token(['entities']))
            ->postJson("/api/boats/{$target->id}/merge", ['duplicate_boat_id' => $duplicate->id])
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.landing.id', $landing->id)
            ->assertJsonPath('data.aliases.*.alias', ['Dolphin 2', 'Dolphin II'])
            ->assertJsonPath('meta.merged_boat.id', $duplicate->id)
            ->assertJsonPath('meta.merged_boat.is_active', false);

        $this->assertSame($target->id, $report->refresh()->boat_id);
        $this->assertSame('2026-07-09|dolphin|full-day|20', $report->dedupe_key);
        $this->assertSame($bystander->id, $bystanderReport->refresh()->boat_id);
        $this->assertSame([$target->id], $alertRule->boats()->pluck('boats.id')->all());
        $this->assertSame(0, $duplicate->aliases()->count());
        $this->assertFalse($duplicate->refresh()->is_active);
        $this->assertTrue($target->refresh()->is_active);
        $this->assertTrue($bystander->refresh()->is_active);
    }

    public function test_merge_rejects_invalid_requests(): void
    {
        $token = $this->token(['entities']);
        $target = Boat::factory()->create();
        $inactiveTarget = Boat::factory()->inactive()->create();
        $duplicate = Boat::factory()->create();

        $this->withToken($token)->postJson("/api/boats/{$target->id}/merge", ['duplicate_boat_id' => $target->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duplicate_boat_id');
        $this->withToken($token)->postJson("/api/boats/{$target->id}/merge", ['duplicate_boat_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duplicate_boat_id');
        $this->withToken($token)->postJson("/api/boats/{$target->id}/merge", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duplicate_boat_id');
        $this->withToken($token)->postJson("/api/boats/{$inactiveTarget->id}/merge", ['duplicate_boat_id' => $duplicate->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duplicate_boat_id');

        $this->assertTrue($duplicate->refresh()->is_active);
    }

    private function landing(): Landing
    {
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);

        return Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities): string
    {
        return $this->agent->createToken('agent', $abilities)->plainTextToken;
    }
}
