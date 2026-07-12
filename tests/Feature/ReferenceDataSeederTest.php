<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ScrapeSourceSeeder;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeders_create_expected_aliases_and_default_rule_filters(): void
    {
        User::factory()->admin()->create([
            'email' => config('fish.admin.email'),
        ]);

        $this->seed(DatabaseSeeder::class);

        $rule = AlertRule::query()->where('name', 'Local Yellowtail')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['1/2 Day', '1/2 Day AM', '1/2 Day PM', '3/4 Day', 'Full Day', 'Full Day Coronado Islands'],
            $rule->tripTypes()->pluck('name')->all(),
        );

        $this->assertSame('1/2 Day', TripTypeAlias::query()->where('normalized_alias', 'half day')->firstOrFail()->tripType->name);
        $this->assertSame('1/2 Day', TripTypeAlias::query()->where('normalized_alias', '4 hour')->firstOrFail()->tripType->name);
        $this->assertSame('1/2 Day Twilight', TripTypeAlias::query()->where('normalized_alias', 'twilight')->firstOrFail()->tripType->name);
        $this->assertSame('Full Day', TripTypeAlias::query()->where('normalized_alias', 'full day offshore')->firstOrFail()->tripType->name);
        $this->assertSame('Full Day', TripTypeAlias::query()->where('normalized_alias', 'full day local')->firstOrFail()->tripType->name);
        $this->assertSame('3/4 Day', TripTypeAlias::query()->where('normalized_alias', '3/4 day local')->firstOrFail()->tripType->name);
        $this->assertSame('3/4 Day', TripTypeAlias::query()->where('normalized_alias', '6 hour')->firstOrFail()->tripType->name);
        $this->assertSame('Long Range', TripTypeAlias::query()->where('normalized_alias', '3.5 day')->firstOrFail()->tripType->name);
        $this->assertSame('Long Range', TripTypeAlias::query()->where('normalized_alias', '4 day')->firstOrFail()->tripType->name);
        $this->assertFalse(TripType::query()->where('name', '3.5 Day')->exists());
        $this->assertSame('Yellowtail', SpeciesAlias::query()->where('normalized_alias', 'yelowtail')->firstOrFail()->species->name);
        $this->assertSame('Bluefin Tuna', SpeciesAlias::query()->where('normalized_alias', 'bleufin tuna')->firstOrFail()->species->name);
        $this->assertSame('Bonito', SpeciesAlias::query()->where('normalized_alias', 'bontio')->firstOrFail()->species->name);
        $this->assertSame('Bonito', SpeciesAlias::query()->where('normalized_alias', 'bonita')->firstOrFail()->species->name);
        $this->assertSame('Barracuda', SpeciesAlias::query()->where('normalized_alias', 'baracuda')->firstOrFail()->species->name);
        $this->assertSame('Cabezon', SpeciesAlias::query()->where('normalized_alias', 'cabazon')->firstOrFail()->species->name);
        $this->assertSame('Lingcod', SpeciesAlias::query()->where('normalized_alias', 'lings')->firstOrFail()->species->name);
        $this->assertSame('White Seabass', SpeciesAlias::query()->where('normalized_alias', 'white sea bass')->firstOrFail()->species->name);
        $this->assertSame('Sand Bass', SpeciesAlias::query()->where('normalized_alias', 'sandbass')->firstOrFail()->species->name);
        $this->assertSame('Sand Bass', SpeciesAlias::query()->where('normalized_alias', 'barred sand bass')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'vermillion rockfish')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'mixed rockfish')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'vermillion red rockfish')->firstOrFail()->species->name);
        $this->assertTrue(Species::query()->where('slug', 'pacific-mackerel')->where('is_active', true)->exists());
        $this->assertTrue(Species::query()->where('slug', 'halfmoon')->where('is_active', true)->exists());
        $this->assertSame('coronado_islands', Species::query()->where('slug', 'yellowtail')->value('environmental_location_profile'));
        $this->assertSame('san_diego_bight', Species::query()->where('slug', 'calico-bass')->value('environmental_location_profile'));
    }

    public function test_species_seeder_preserves_admin_condition_profile_changes(): void
    {
        $species = Species::query()->create([
            'name' => 'Yellowtail',
            'slug' => 'yellowtail',
            'environmental_location_profile' => 'san_diego_bight',
        ]);

        $this->seed(SpeciesSeeder::class);

        $this->assertSame('san_diego_bight', $species->fresh()->environmental_location_profile);
    }

    public function test_scrape_source_seeder_removes_retired_sources(): void
    {
        ScrapeSource::query()->create([
            'name' => 'Retired Reports',
            'slug' => 'retired_reports',
            'source_type' => SourceType::Fallback,
            'base_url' => 'https://example.com',
        ]);

        $this->seed(ScrapeSourceSeeder::class);

        $this->assertDatabaseMissing('scrape_sources', [
            'slug' => 'retired_reports',
        ]);
    }
}
