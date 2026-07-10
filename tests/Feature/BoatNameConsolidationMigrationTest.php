<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\Landing;
use App\Models\Region;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\TripReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoatNameConsolidationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_consolidates_variants_and_preserves_dependencies(): void
    {
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create([
            'region_id' => $region->id,
            'name' => "Fisherman's Landing",
            'slug' => 'fishermans-landing',
        ]);
        $canonicalBoat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Lucky B',
            'slug' => 'lucky-b',
        ]);
        $variantBoat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Lucky B Sportfishing',
            'slug' => 'lucky-b-sportfishing',
        ]);
        $source = ScrapeSource::query()->create([
            'name' => "Fisherman's Landing",
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $tripReport = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => $variantBoat->id,
            'trip_date' => '2026-07-09',
            'raw_boat_name' => 'Lucky B Sportfishing',
            'dedupe_key' => 'lucky-b-variant',
        ]);
        BoatAlias::query()->create([
            'boat_id' => $variantBoat->id,
            'alias' => 'The Lucky B',
            'normalized_alias' => 'the lucky b',
        ]);
        $alertRule = AlertRule::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Lucky B alerts',
            'species_id' => Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail'])->id,
        ]);
        $alertRule->boats()->attach([$canonicalBoat->id, $variantBoat->id]);

        $migration = require database_path('migrations/2026_07_10_211721_consolidate_misparsed_boat_names.php');
        $migration->up();

        $this->assertSame($canonicalBoat->id, $tripReport->fresh()->boat_id);
        $this->assertFalse($variantBoat->fresh()->is_active);
        $this->assertSame($canonicalBoat->id, BoatAlias::query()->where('normalized_alias', 'the lucky b')->value('boat_id'));
        $this->assertSame($canonicalBoat->id, BoatAlias::query()->where('normalized_alias', 'lucky b sportfishing')->value('boat_id'));
        $this->assertSame([$canonicalBoat->id], $alertRule->boats()->pluck('boats.id')->all());

        $migration->up();

        $this->assertSame(2, BoatAlias::query()->count());
        $this->assertSame([$canonicalBoat->id], $alertRule->boats()->pluck('boats.id')->all());
    }

    public function test_migration_rejects_a_variant_when_its_canonical_boat_is_missing(): void
    {
        Boat::query()->create([
            'name' => 'Lucky B Sportfishing',
            'slug' => 'lucky-b-sportfishing',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing canonical boat [Lucky B].');

        $migration = require database_path('migrations/2026_07_10_211721_consolidate_misparsed_boat_names.php');
        $migration->up();
    }
}
