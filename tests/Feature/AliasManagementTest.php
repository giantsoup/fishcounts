<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\Landing;
use App\Models\ParserError;
use App\Models\Region;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AliasManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_boat_management_page(): void
    {
        $admin = User::factory()->admin()->create();
        Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);

        $this->actingAs($admin)
            ->get(route('admin.boats.index'))
            ->assertOk()
            ->assertSeeText('Active boats')
            ->assertSeeText('Dolphin')
            ->assertSeeText('Select a boat to edit it or consolidate another name into it.')
            ->assertSee('Save boat');
    }

    public function test_admin_can_create_boat(): void
    {
        $admin = User::factory()->admin()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Seaforth Landing', 'slug' => 'seaforth-landing']);

        $this->actingAs($admin)
            ->post(route('admin.boats.store'), [
                'boat_name' => 'New Seaforth',
                'landing_id' => $landing->id,
            ])
            ->assertRedirect(route('admin.boats.index'))
            ->assertSessionHas('status', 'Boat saved.');

        $this->assertDatabaseHas('boats', [
            'landing_id' => $landing->id,
            'name' => 'New Seaforth',
            'slug' => 'new-seaforth',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_consolidate_existing_boat_variant(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $canonicalBoat = Boat::query()->create([
            'name' => 'Pacific Queen',
            'slug' => 'pacific-queen',
            'booking_url' => 'https://canonical.example.test',
        ]);
        $variantBoat = Boat::query()->create([
            'name' => 'The Pacific Queen',
            'slug' => 'the-pacific-queen',
            'booking_url' => 'https://variant.example.test',
            'booking_provider_identifier' => 'provider-42',
        ]);
        BoatAlias::query()->create([
            'boat_id' => $variantBoat->id,
            'alias' => 'The Pacific Queen',
            'normalized_alias' => 'the pacific queen',
        ]);
        $tripReport = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => $variantBoat->id,
            'trip_date' => '2026-07-09',
            'dedupe_key' => 'variant-report',
            'source_confidence' => 80,
        ]);
        $canonicalTripReport = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => $canonicalBoat->id,
            'trip_date' => '2026-07-09',
            'dedupe_key' => '2026-07-09|pacific-queen|unknown-trip|unknown-anglers',
            'source_confidence' => 90,
        ]);
        $alertRule = AlertRule::query()->create([
            'user_id' => $admin->id,
            'name' => 'Yellowtail alert',
            'species_id' => $species->id,
        ]);
        $alertRule->boats()->attach($variantBoat);

        $this->actingAs($admin)
            ->post(route('admin.boat-aliases.store'), [
                'boat_id' => $canonicalBoat->id,
                'alias' => 'The Pacific Queen',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Boat alias saved.')
            ->assertSessionHas('selected_boat_id', $canonicalBoat->id);

        $this->assertDatabaseHas('boat_aliases', [
            'boat_id' => $canonicalBoat->id,
            'alias' => 'The Pacific Queen',
            'normalized_alias' => 'the pacific queen',
        ]);
        $this->assertSame($canonicalBoat->id, $tripReport->fresh()->boat_id);
        $this->assertFalse($tripReport->fresh()->is_deduped_primary);
        $this->assertTrue($canonicalTripReport->fresh()->is_deduped_primary);
        $this->assertFalse($variantBoat->fresh()->is_active);
        $this->assertSame('https://canonical.example.test', $canonicalBoat->fresh()->booking_url);
        $this->assertSame('provider-42', $canonicalBoat->fresh()->booking_provider_identifier);
        $this->assertDatabaseHas('alert_rule_boat', [
            'alert_rule_id' => $alertRule->id,
            'boat_id' => $canonicalBoat->id,
        ]);
        $this->assertDatabaseMissing('alert_rule_boat', [
            'alert_rule_id' => $alertRule->id,
            'boat_id' => $variantBoat->id,
        ]);
    }

    public function test_admin_can_resolve_boat_parser_error_by_creating_alias(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $error = ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-09',
            'error_type' => 'unknown_boat_alias',
            'raw_field' => 'boat',
            'raw_value' => 'The Dolphin',
            'message' => 'Unknown boat alias [The Dolphin].',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.boat-aliases.store'), [
                'boat_id' => $boat->id,
                'alias' => 'The Dolphin',
                'parser_error_id' => $error->id,
            ])
            ->assertRedirect();

        $this->assertNotNull($error->fresh()->resolved_at);
        $this->assertSame($admin->id, $error->fresh()->resolved_by_user_id);
    }

    public function test_creating_boat_alias_backfills_previously_unmatched_reports(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $tripReport = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => null,
            'trip_date' => '2026-07-09',
            'raw_boat_name' => 'The Dolphin',
            'raw_trip_type' => 'Full Day',
            'anglers' => 20,
            'dedupe_key' => 'unmatched-report',
            'is_deduped_primary' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.boat-aliases.store'), [
                'boat_id' => $boat->id,
                'alias' => 'The Dolphin',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $tripReport->refresh();

        $this->assertSame($boat->id, $tripReport->boat_id);
        $this->assertSame('2026-07-09|dolphin|full-day|20', $tripReport->dedupe_key);
        $this->assertTrue($tripReport->is_deduped_primary);
    }

    public function test_existing_normalized_boat_alias_is_idempotent_and_resolves_variants(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        BoatAlias::query()->create(['boat_id' => $boat->id, 'alias' => 'The Dolphin', 'normalized_alias' => 'the dolphin']);
        $reports = collect(['The Dolphin', 'THE DOLPHIN!'])->map(fn (string $rawBoatName, int $index): TripReport => TripReport::query()->create([
            'source_id' => $source->id,
            'trip_date' => '2026-07-09',
            'raw_boat_name' => $rawBoatName,
            'dedupe_key' => 'unmatched-'.$index,
        ]));
        $errors = collect(['The Dolphin', 'THE DOLPHIN!'])->map(fn (string $rawBoatName): ParserError => ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-09',
            'error_type' => 'unknown_boat_alias',
            'raw_field' => 'boat',
            'raw_value' => $rawBoatName,
            'message' => "Unknown boat alias [{$rawBoatName}].",
        ]));
        $unrelatedError = ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-09',
            'error_type' => 'unexpected_boat_data',
            'raw_field' => 'boat',
            'raw_value' => 'The Dolphin',
            'message' => 'Unexpected boat data.',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.boats.index'))
            ->post(route('admin.boat-aliases.store'), [
                'boat_id' => $boat->id,
                'alias' => 'THE DOLPHIN!',
            ])
            ->assertRedirect(route('admin.boats.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, BoatAlias::query()->count());
        $this->assertSame([$boat->id], $reports->map(fn (TripReport $tripReport): ?int => $tripReport->fresh()->boat_id)->unique()->values()->all());
        $this->assertTrue($errors->every(fn (ParserError $parserError): bool => $parserError->fresh()->resolved_at !== null));
        $this->assertNull($unrelatedError->fresh()->resolved_at);
    }

    public function test_boat_cannot_be_created_from_an_existing_alias(): void
    {
        $admin = User::factory()->admin()->create();
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        BoatAlias::query()->create(['boat_id' => $boat->id, 'alias' => 'The Dolphin', 'normalized_alias' => 'the dolphin']);

        $this->actingAs($admin)
            ->from(route('admin.boats.index'))
            ->post(route('admin.boats.store'), ['boat_name' => 'The Dolphin!'])
            ->assertRedirect(route('admin.boats.index'))
            ->assertSessionHasErrors('boat_name');

        $this->assertSame(1, Boat::query()->count());
    }

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
            ->assertSeeText('Condition profile')
            ->assertSeeText('San Diego — Local')
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
                'environmental_location_profile' => 'coronado_islands',
            ])
            ->assertRedirect(route('admin.species-aliases.index'))
            ->assertSessionHas('status', 'Species saved.');

        $this->assertDatabaseHas('species', [
            'name' => 'Soupfin Shark',
            'slug' => 'soupfin-shark',
            'environmental_location_profile' => 'coronado_islands',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_a_species_condition_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);

        $this->actingAs($admin)
            ->patch(route('admin.species.update', $species), [
                'species_id' => $species->id,
                'species_environmental_location_profile' => 'coronado_islands',
            ])
            ->assertRedirect(route('admin.species-aliases.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Species condition profile updated.')
            ->assertSessionHas('selected_species_id', $species->id);

        $this->assertSame('coronado_islands', $species->fresh()->environmental_location_profile);
    }

    public function test_species_condition_profile_must_be_configured(): void
    {
        $admin = User::factory()->admin()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);

        $this->actingAs($admin)
            ->from(route('admin.species-aliases.index'))
            ->patch(route('admin.species.update', $species), [
                'species_id' => $species->id,
                'species_environmental_location_profile' => 'unknown',
            ])
            ->assertRedirect(route('admin.species-aliases.index'))
            ->assertSessionHasErrors('species_environmental_location_profile');

        $this->assertSame('san_diego_bight', $species->fresh()->environmental_location_profile);
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
