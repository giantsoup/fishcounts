<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\ParserError;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use Database\Seeders\SpeciesSeeder;
use Database\Seeders\TripTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ParsingErrorCorrectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_only_deterministic_aliases_and_is_idempotent(): void
    {
        $this->seed([SpeciesSeeder::class, TripTypeSeeder::class]);

        $rockfish = Species::query()->where('slug', 'rockfish')->firstOrFail();
        $lingcod = Species::query()->where('slug', 'lingcod')->firstOrFail();
        $bonito = Species::query()->where('slug', 'bonito')->firstOrFail();
        $fullDay = TripType::query()->where('name', 'Full Day')->firstOrFail();

        SpeciesAlias::query()->create([
            'species_id' => $rockfish->id,
            'alias' => 'Lings',
            'normalized_alias' => 'lings',
        ]);
        SpeciesAlias::query()->create([
            'species_id' => $bonito->id,
            'alias' => 'Baracuda',
            'normalized_alias' => 'baracuda',
        ]);
        TripTypeAlias::query()->create([
            'trip_type_id' => $fullDay->id,
            'alias' => '3/4 Day Local',
            'normalized_alias' => '3/4 day local',
        ]);

        $source = ScrapeSource::query()->create([
            'name' => 'Parser Migration Test',
            'slug' => 'parser_migration_test',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://example.test',
        ]);
        $ambiguousError = ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-10',
            'error_type' => 'unknown_trip_type_alias',
            'raw_field' => 'trip_type',
            'raw_value' => '4 Day',
            'message' => 'Unknown trip_type alias [4 Day].',
        ]);

        $migration = require database_path('migrations/2026_07_10_202851_correct_obvious_parser_errors.php');

        $migration->up();
        $migration->up();

        $this->assertSame($lingcod->id, SpeciesAlias::query()->where('normalized_alias', 'lings')->firstOrFail()->species_id);
        $this->assertSame('Barracuda', SpeciesAlias::query()->where('normalized_alias', 'baracuda')->firstOrFail()->species->name);
        $this->assertSame('Yellowtail', SpeciesAlias::query()->where('normalized_alias', 'yelowtail')->firstOrFail()->species->name);
        $this->assertSame('Bluefin Tuna', SpeciesAlias::query()->where('normalized_alias', 'bleufin tuna')->firstOrFail()->species->name);
        $this->assertSame('Bonito', SpeciesAlias::query()->where('normalized_alias', 'bontio')->firstOrFail()->species->name);
        $this->assertSame('Cabezon', SpeciesAlias::query()->where('normalized_alias', 'cabazon')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'assorted rockfish')->firstOrFail()->species->name);
        $this->assertSame('3/4 Day', TripTypeAlias::query()->where('normalized_alias', '3/4 day local')->firstOrFail()->tripType->name);
        $this->assertSame('Full Day', TripTypeAlias::query()->where('normalized_alias', 'full day local')->firstOrFail()->tripType->name);
        $this->assertSame('White Seabass', SpeciesAlias::query()->where('normalized_alias', 'white sea bass')->firstOrFail()->species->name);

        $this->assertDatabaseMissing('trip_type_aliases', ['normalized_alias' => '4 day']);
        $this->assertNull($ambiguousError->fresh()->resolved_at);
    }

    public function test_migration_fails_loudly_when_canonical_reference_data_is_partially_missing(): void
    {
        $this->seed([SpeciesSeeder::class, TripTypeSeeder::class]);
        Species::query()->where('slug', 'white-seabass')->delete();

        $migration = require database_path('migrations/2026_07_10_202851_correct_obvious_parser_errors.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing canonical species [white-seabass].');

        $migration->up();
    }
}
