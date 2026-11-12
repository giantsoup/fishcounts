<?php

namespace Tests\Feature\Api;

use App\Models\Boat;
use App\Models\Landing;
use App\Models\Region;
use App\Models\Species;
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

        $this->withToken($token)->postJson('/api/boats', ['name' => 'Lone Star'])->assertForbidden();
        $this->withToken($token)->postJson('/api/species', ['name' => 'Opaleye'])->assertForbidden();
        $this->withToken($token)->postJson('/api/trip-types', ['name' => 'Twilight'])->assertForbidden();
        $this->withToken($token)->patchJson("/api/boats/{$boat->id}", ['is_active' => false])->assertForbidden();

        $this->assertTrue($boat->refresh()->is_active);
        $this->assertDatabaseMissing('boats', ['name' => 'Lone Star']);
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
