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

    public function test_admin_can_view_species_management_page(): void
    {
        $admin = User::factory()->admin()->create();
        Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);

        $this->actingAs($admin)
            ->get(route('admin.species-aliases.index'))
            ->assertOk()
            ->assertSeeText('Species')
            ->assertSeeText('Active species')
            ->assertSee('Save species')
            ->assertSeeText('Select a species to edit it.')
            ->assertSeeText('Yellowtail')
            ->assertDontSeeText('Species aliases')
            ->assertDontSeeText('Add species alias')
            ->assertDontSee('yellowtail');
    }

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
            ->assertRedirect()
            ->assertSessionHas('selected_species_id', $species->id);

        $this->assertDatabaseHas('species_aliases', [
            'species_id' => $species->id,
            'alias' => 'Calicos',
            'normalized_alias' => 'calicos',
        ]);
        $this->assertNotNull($error->fresh()->resolved_at);
    }

    public function test_admin_can_create_species(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.species.store'), [
                'name' => 'Soupfin Shark',
            ])
            ->assertRedirect(route('admin.species-aliases.index'))
            ->assertSessionHas('status', 'Species saved.');

        $this->assertDatabaseHas('species', [
            'name' => 'Soupfin Shark',
            'slug' => 'soupfin-shark',
            'is_active' => true,
        ]);
    }

    public function test_duplicate_species_slug_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        Species::query()->create(['name' => 'Mako Shark', 'slug' => 'mako-shark']);

        $this->actingAs($admin)
            ->from(route('admin.species-aliases.index'))
            ->post(route('admin.species.store'), [
                'name' => 'Mako Shark!',
            ])
            ->assertRedirect(route('admin.species-aliases.index'))
            ->assertSessionHasErrors('name');
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
            ->assertRedirect()
            ->assertSessionHas('selected_trip_type_id', $tripType->id);

        $this->assertDatabaseHas('trip_type_aliases', [
            'trip_type_id' => $tripType->id,
            'alias' => 'Three Quarter Day',
            'normalized_alias' => 'three quarter day',
        ]);
        $this->assertNotNull($error->fresh()->resolved_at);
    }

    public function test_admin_can_view_trip_type_management_page(): void
    {
        $admin = User::factory()->admin()->create();
        TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);

        $this->actingAs($admin)
            ->get(route('admin.trip-type-aliases.index'))
            ->assertOk()
            ->assertSeeText('Trips')
            ->assertSeeText('Active trips')
            ->assertSee('Save trip')
            ->assertSeeText('Select a trip to edit it.')
            ->assertSeeText('Full Day')
            ->assertDontSeeText('Trip type aliases')
            ->assertDontSeeText('Add trip type alias');
    }

    public function test_admin_can_create_trip_type(): void
    {
        $admin = User::factory()->admin()->create();
        TripType::query()->create(['name' => '3 Day', 'slug' => '3-day', 'sort_order' => 3]);

        $this->actingAs($admin)
            ->post(route('admin.trip-types.store'), [
                'name' => '3.5 Day',
            ])
            ->assertRedirect(route('admin.trip-type-aliases.index'))
            ->assertSessionHas('status', 'Trip type saved.');

        $this->assertDatabaseHas('trip_types', [
            'name' => '3.5 Day',
            'slug' => '35-day',
            'sort_order' => 4,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_trip_type_order(): void
    {
        $admin = User::factory()->admin()->create();
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day', 'sort_order' => 6]);

        $this->actingAs($admin)
            ->from(route('admin.trip-type-aliases.index'))
            ->patch(route('admin.trip-types.update', $tripType), [
                'order_trip_type_id' => $tripType->id,
                'order_sort_order' => 2,
            ])
            ->assertRedirect(route('admin.trip-type-aliases.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Trip order saved.')
            ->assertSessionHas('selected_trip_type_id', $tripType->id);

        $this->assertDatabaseHas('trip_types', [
            'id' => $tripType->id,
            'sort_order' => 2,
        ]);
    }

    public function test_trip_type_order_can_duplicate_existing_order(): void
    {
        $admin = User::factory()->admin()->create();
        $halfDay = TripType::query()->create(['name' => '1/2 Day', 'slug' => '1-2-day', 'sort_order' => 1]);
        $fullDay = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day', 'sort_order' => 6]);

        $this->actingAs($admin)
            ->from(route('admin.trip-type-aliases.index'))
            ->patch(route('admin.trip-types.update', $fullDay), [
                'order_trip_type_id' => $fullDay->id,
                'order_sort_order' => 1,
            ])
            ->assertRedirect(route('admin.trip-type-aliases.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('trip_types', [
            'id' => $halfDay->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('trip_types', [
            'id' => $fullDay->id,
            'sort_order' => 1,
        ]);
    }

    public function test_trip_types_use_deterministic_display_order(): void
    {
        $admin = User::factory()->admin()->create();
        $firstSameName = TripType::query()->create(['name' => 'Same Trip', 'slug' => 'same-trip-one', 'sort_order' => 1]);
        $secondSameName = TripType::query()->create(['name' => 'Same Trip', 'slug' => 'same-trip-two', 'sort_order' => 1]);
        $alphabeticalFirst = TripType::query()->create(['name' => 'Alpha Trip', 'slug' => 'alpha-trip', 'sort_order' => 1]);
        $laterOrder = TripType::query()->create(['name' => 'Beta Trip', 'slug' => 'beta-trip', 'sort_order' => 2]);

        $this->actingAs($admin)
            ->get(route('admin.trip-type-aliases.index'))
            ->assertOk()
            ->assertViewHas('tripTypes', fn ($tripTypes): bool => $tripTypes->pluck('id')->all() === [
                $alphabeticalFirst->id,
                $firstSameName->id,
                $secondSameName->id,
                $laterOrder->id,
            ]);
    }

    public function test_duplicate_trip_type_slug_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);

        $this->actingAs($admin)
            ->from(route('admin.trip-type-aliases.index'))
            ->post(route('admin.trip-types.store'), [
                'name' => 'Full Day!',
            ])
            ->assertRedirect(route('admin.trip-type-aliases.index'))
            ->assertSessionHasErrors('name');
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
