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

class EntityLookupTest extends TestCase
{
    use RefreshDatabase;

    private ?string $token = null;

    public function test_boats_list_includes_inactive_boats_and_embeds_aliases(): void
    {
        $active = Boat::factory()->create(['name' => 'Pacific Queen']);
        $active->aliases()->create(['alias' => 'Pac Queen', 'normalized_alias' => 'pac queen']);
        $inactive = Boat::factory()->inactive()->create(['name' => 'Old Glory']);

        $this->withToken($this->readToken())
            ->getJson('/api/boats')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $inactive->id)
            ->assertJsonPath('data.0.name', 'Old Glory')
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.1.id', $active->id)
            ->assertJsonPath('data.1.is_active', true)
            ->assertJsonPath('data.1.aliases.0.alias', 'Pac Queen')
            ->assertJsonPath('data.1.aliases.0.normalized_alias', 'pac queen')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_search_matches_name_alias_or_normalized_alias(): void
    {
        $byName = Boat::factory()->create(['name' => 'Pacific Queen']);
        $byAlias = Boat::factory()->create(['name' => 'Liberty']);
        $byAlias->aliases()->create(['alias' => 'Liberty II', 'normalized_alias' => 'liberty ii']);
        $byNormalizedAlias = Boat::factory()->create(['name' => 'Sea Watch']);
        $byNormalizedAlias->aliases()->create(['alias' => 'SeaWatch!!', 'normalized_alias' => 'seawatch']);
        Boat::factory()->create(['name' => 'Dolphin']);

        $this->assertSearchFinds('/api/boats', 'queen', [$byName->id]);
        $this->assertSearchFinds('/api/boats', 'liberty ii', [$byAlias->id]);
        $this->assertSearchFinds('/api/boats', 'SEAWATCH', [$byNormalizedAlias->id]);
        $this->assertSearchFinds('/api/boats', '100%', []);
    }

    public function test_show_returns_one_boat_with_landing_and_aliases(): void
    {
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['name' => 'Fisherman\'s Landing', 'slug' => 'fishermans', 'region_id' => $region->id]);
        $boat = Boat::factory()->inactive()->create(['name' => 'Pacific Queen', 'landing_id' => $landing->id]);
        $boat->aliases()->create(['alias' => 'Pac Queen', 'normalized_alias' => 'pac queen']);

        $this->withToken($this->readToken())
            ->getJson("/api/boats/{$boat->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $boat->id)
            ->assertJsonPath('data.name', 'Pacific Queen')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.landing.name', 'Fisherman\'s Landing')
            ->assertJsonPath('data.aliases.0.alias', 'Pac Queen');
    }

    public function test_species_and_trip_types_list_search_and_show(): void
    {
        $species = Species::factory()->inactive()->create(['name' => 'Yellowtail']);
        $species->aliases()->create(['alias' => 'Yellows', 'normalized_alias' => 'yellows']);
        Species::factory()->create(['name' => 'Bluefin Tuna']);
        $tripType = TripType::factory()->create(['name' => 'Overnight', 'sort_order' => 7]);
        $tripType->aliases()->create(['alias' => 'Over Night', 'normalized_alias' => 'over night']);
        $inactiveTripType = TripType::factory()->inactive()->create(['name' => 'Retired Run']);

        $this->assertSearchFinds('/api/species', 'yellows', [$species->id]);
        $this->assertSearchFinds('/api/trip-types', 'over night', [$tripType->id]);

        $token = $this->readToken();
        $this->assertContains($species->id, $this->withToken($token)->getJson('/api/species')->assertOk()->json('data.*.id'));
        $tripTypeIds = $this->withToken($token)->getJson('/api/trip-types')->assertOk()->json('data.*.id');
        $this->assertContains($tripType->id, $tripTypeIds);
        $this->assertContains($inactiveTripType->id, $tripTypeIds);

        $this->withToken($token)
            ->getJson("/api/species/{$species->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Yellowtail')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.aliases.0.alias', 'Yellows');

        $this->withToken($token)
            ->getJson("/api/trip-types/{$tripType->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Overnight')
            ->assertJsonPath('data.sort_order', 7)
            ->assertJsonPath('data.aliases.0.alias', 'Over Night');
    }

    public function test_missing_record_returns_json_404(): void
    {
        $token = $this->readToken();

        foreach (['/api/boats/999', '/api/species/999', '/api/trip-types/999', '/api/boats/abc'] as $path) {
            $this->withToken($token)->getJson($path)->assertNotFound();
        }
    }

    public function test_invalid_list_queries_get_json_422(): void
    {
        $token = $this->readToken();

        $this->withToken($token)->getJson('/api/boats?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->withToken($token)->getJson('/api/species?search[]=x')->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->withToken($token)->getJson('/api/trip-types?page=x')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_lookups_need_the_read_ability(): void
    {
        $token = User::factory()->admin()->create()->createToken('agent', ['aliases'])->plainTextToken;

        $this->withToken($token)->getJson('/api/boats')->assertForbidden();
    }

    /**
     * @param  list<int>  $expectedIds
     */
    private function assertSearchFinds(string $path, string $search, array $expectedIds): void
    {
        $this->app['auth']->forgetGuards();

        $ids = $this->withToken($this->readToken())
            ->getJson($path.'?'.http_build_query(['search' => $search]))
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame($expectedIds, $ids, "{$path} search={$search}");
    }

    private function readToken(): string
    {
        return $this->token ??= User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
    }
}
