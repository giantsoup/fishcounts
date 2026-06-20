<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\ScrapeSource;
use App\Models\SpeciesAlias;
use App\Models\TripTypeAlias;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ScrapeSourceSeeder;
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
        $this->assertSame('Sand Bass', SpeciesAlias::query()->where('normalized_alias', 'sandbass')->firstOrFail()->species->name);
        $this->assertSame('Sand Bass', SpeciesAlias::query()->where('normalized_alias', 'barred sand bass')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'vermillion rockfish')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'mixed rockfish')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'vermillion red rockfish')->firstOrFail()->species->name);
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
