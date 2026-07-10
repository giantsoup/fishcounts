<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use App\Models\User;
use Database\Seeders\SpeciesAliasSeeder;
use Database\Seeders\SpeciesSeeder;
use Database\Seeders\TripTypeAliasSeeder;
use Database\Seeders\TripTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RemainingParserErrorsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_confirmed_species_and_corrects_existing_aliases_idempotently(): void
    {
        $this->clearReferenceData();

        $bonito = Species::query()->create(['name' => 'Bonito', 'slug' => 'bonito']);
        $wrongSpecies = Species::query()->create(['name' => 'Barracuda', 'slug' => 'barracuda']);
        $threeQuarterDay = TripType::query()->create(['name' => '3/4 Day', 'slug' => '34-day']);
        $longRange = TripType::query()->create(['name' => 'Long Range', 'slug' => 'long-range']);
        $legacyThreeAndHalfDay = TripType::query()->create(['name' => '3.5 Day', 'slug' => '35-day']);
        $wrongTripType = TripType::query()->create(['name' => 'Unknown', 'slug' => 'unknown']);
        SpeciesAlias::query()->create(['species_id' => $wrongSpecies->id, 'alias' => 'Bonita', 'normalized_alias' => 'bonita']);
        TripTypeAlias::query()->create(['trip_type_id' => $wrongTripType->id, 'alias' => '6 Hour', 'normalized_alias' => '6 hour']);
        TripTypeAlias::query()->create(['trip_type_id' => $wrongTripType->id, 'alias' => '4 Day', 'normalized_alias' => '4 day']);
        TripTypeAlias::query()->create(['trip_type_id' => $legacyThreeAndHalfDay->id, 'alias' => '3.5 Day', 'normalized_alias' => '3.5 day']);
        $source = ScrapeSource::query()->create([
            'name' => 'Test source',
            'slug' => 'test_source',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://example.test',
        ]);
        $tripReport = TripReport::query()->create([
            'source_id' => $source->id,
            'trip_type_id' => $legacyThreeAndHalfDay->id,
            'trip_date' => '2026-07-10',
            'dedupe_key' => 'legacy-three-and-half-day',
        ]);
        $alertRule = AlertRule::query()->create([
            'user_id' => User::factory()->create()->id,
            'species_id' => $bonito->id,
            'name' => 'Long range alert',
        ]);
        $alertRule->tripTypes()->attach([$legacyThreeAndHalfDay->id, $longRange->id]);

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertSame(1, Species::query()->where('slug', 'pacific-mackerel')->where('is_active', true)->count());
        $this->assertSame(1, Species::query()->where('slug', 'halfmoon')->where('is_active', true)->count());
        $this->assertSame($bonito->id, SpeciesAlias::query()->where('normalized_alias', 'bonita')->value('species_id'));
        $this->assertSame($threeQuarterDay->id, TripTypeAlias::query()->where('normalized_alias', '6 hour')->value('trip_type_id'));
        $this->assertSame($longRange->id, TripTypeAlias::query()->where('normalized_alias', '4 day')->value('trip_type_id'));
        $this->assertSame($longRange->id, TripTypeAlias::query()->where('normalized_alias', '3 5 day')->value('trip_type_id'));
        $this->assertSame($longRange->id, $tripReport->fresh()->trip_type_id);
        $this->assertFalse($legacyThreeAndHalfDay->fresh()->is_active);
        $this->assertSame([$longRange->id], $alertRule->tripTypes()->pluck('trip_types.id')->all());
        $this->assertSame(1, SpeciesAlias::query()->where('normalized_alias', 'bonita')->count());
        $this->assertSame(3, TripTypeAlias::query()->whereIn('normalized_alias', ['6 hour', '4 day', '3 5 day'])->count());
    }

    public function test_migration_and_seeders_are_safe_with_empty_reference_tables(): void
    {
        $this->clearReferenceData();

        $this->migration()->up();

        $this->assertSame(['halfmoon', 'pacific-mackerel'], Species::query()->orderBy('slug')->pluck('slug')->all());
        $this->assertSame(0, SpeciesAlias::query()->count());
        $this->assertSame(0, TripTypeAlias::query()->count());

        $this->seed([SpeciesSeeder::class, SpeciesAliasSeeder::class, TripTypeSeeder::class, TripTypeAliasSeeder::class]);

        $this->assertSame('Bonito', SpeciesAlias::query()->where('normalized_alias', 'bonita')->firstOrFail()->species->name);
        $this->assertSame('3/4 Day', TripTypeAlias::query()->where('normalized_alias', '6 hour')->firstOrFail()->tripType->name);
        $this->assertSame('Long Range', TripTypeAlias::query()->where('normalized_alias', '4 day')->firstOrFail()->tripType->name);
    }

    public function test_migration_rolls_back_when_populated_reference_data_is_incomplete(): void
    {
        $this->clearReferenceData();
        Species::query()->create(['name' => 'Barracuda', 'slug' => 'barracuda']);

        try {
            $this->migration()->up();
            $this->fail('The migration should reject incomplete populated reference data.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Missing canonical species [bonito].', $exception->getMessage());
        }

        $this->assertFalse(Species::query()->whereIn('slug', ['pacific-mackerel', 'halfmoon'])->exists());
    }

    private function clearReferenceData(): void
    {
        DB::table('species_aliases')->delete();
        DB::table('trip_type_aliases')->delete();
        DB::table('species')->delete();
        DB::table('trip_types')->delete();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_10_213318_correct_remaining_parser_errors.php');
    }
}
