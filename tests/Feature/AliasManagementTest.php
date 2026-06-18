<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\ParserError;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AliasManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_resolve_species_parser_error_by_creating_alias(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $species = Species::query()->create(['name' => 'Calico Bass', 'slug' => 'calico-bass']);
        $error = ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-17',
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Calicos',
            'message' => 'Unknown species alias [Calicos].',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.species-aliases.store'), [
                'species_id' => $species->id,
                'alias' => 'Calicos',
                'parser_error_id' => $error->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('species_aliases', [
            'species_id' => $species->id,
            'alias' => 'Calicos',
            'normalized_alias' => 'calicos',
        ]);
        $this->assertNotNull($error->fresh()->resolved_at);
    }

    public function test_admin_can_resolve_trip_type_parser_error_by_creating_alias(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $tripType = TripType::query()->create(['name' => '3/4 Day', 'slug' => '3-4-day']);
        $error = ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-17',
            'error_type' => 'unknown_trip_type_alias',
            'raw_field' => 'trip_type',
            'raw_value' => 'Three Quarter Day',
            'message' => 'Unknown trip_type alias [Three Quarter Day].',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.trip-type-aliases.store'), [
                'trip_type_id' => $tripType->id,
                'alias' => 'Three Quarter Day',
                'parser_error_id' => $error->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('trip_type_aliases', [
            'trip_type_id' => $tripType->id,
            'alias' => 'Three Quarter Day',
            'normalized_alias' => 'three quarter day',
        ]);
        $this->assertNotNull($error->fresh()->resolved_at);
    }

    public function test_duplicate_normalized_alias_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        SpeciesAlias::query()->create([
            'species_id' => $species->id,
            'alias' => 'YT',
            'normalized_alias' => 'yt',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.species-aliases.index'))
            ->post(route('admin.species-aliases.store'), [
                'species_id' => $species->id,
                'alias' => 'YT!',
            ])
            ->assertRedirect(route('admin.species-aliases.index'))
            ->assertSessionHasErrors('alias');
    }

    private function source(): ScrapeSource
    {
        return ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
    }
}
