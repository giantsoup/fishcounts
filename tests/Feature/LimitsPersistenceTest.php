<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\Enums\CatchQuantityBasis;
use App\Enums\ScrapeRunType;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\User;
use App\Services\Parsing\CatchCountFormatter;
use App\Services\Parsing\ParsedCollectionSnapshot;
use App\Services\Parsing\TripReportNormalizer;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LimitsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_zero_and_mixed_components_survive_storage_display_and_replay(): void
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => '2026-10-07']);
        $payload = RawScrapePayload::query()->create(['scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => '2026-10-07', 'url' => 'https://example.test', 'payload' => 'limits of Calico Bass', 'payload_hash' => hash('sha256', 'test'), 'fetched_at' => now()]);
        $rockfishId = Species::query()->where('slug', 'rockfish')->value('id');
        $parsed = new ParsedFishCountCollection(collect([new ParsedTripReportData('fishermans_landing', CarbonImmutable::parse('2026-10-07'), 'San Diego', "Fisherman's Landing", 'Dolphin', '3/4 Day', 25, 'Limits Calico Bass', [
            new ParsedSpeciesCountData('Calico Bass', null, quantityBasis: CatchQuantityBasis::LimitsUnquantified, isLimits: true),
            new ParsedSpeciesCountData('Bonito', 0),
            new ParsedSpeciesCountData('Rockfish', 10, canonicalSpeciesId: $rockfishId),
            new ParsedSpeciesCountData('Red Rock Fish', null, canonicalSpeciesId: $rockfishId, quantityBasis: CatchQuantityBasis::LimitsUnquantified, isLimits: true),
        ])]));
        $snapshots = app(ParsedCollectionSnapshot::class);
        $restored = $snapshots->restore($snapshots->make($parsed));
        $this->assertNull(collect($restored->tripReports[0]->speciesCounts)->firstWhere('speciesName', 'Calico Bass')->count);
        $normalizer = app(TripReportNormalizer::class);
        foreach ([1, 2] as $attempt) {
            $normalizer->replaceForPayload($payload, $restored, []);
            $normalizer->refreshPrimaryReports('2026-10-07');
            $counts = SpeciesCount::query()->with('species')->get()->keyBy('species.name');
            $this->assertNull($counts['Calico Bass']->count, json_encode($counts->toArray()));
            $this->assertSame('Limits Calico Bass', app(CatchCountFormatter::class)->count($counts['Calico Bass']));
            $this->assertSame('0 Bonito', app(CatchCountFormatter::class)->count($counts['Bonito']));
            $this->assertSame(10, $counts['Rockfish']->count);
            $this->assertSame(CatchQuantityBasis::Mixed, $counts['Rockfish']->quantity_basis);
            $this->assertTrue($counts['Rockfish']->has_unquantified_components);
            $this->assertSame('10 Rockfish, Limits Red Rock Fish', app(CatchCountFormatter::class)->count($counts['Rockfish']));
        }
        $this->actingAs(User::factory()->create())->get(route('counts.index', ['from' => '2026-10-07', 'to' => '2026-10-07']))->assertOk()->assertSee('Limits Calico Bass')->assertSee('Numeric totals are incomplete');
    }

    public function test_snapshot_legacy_counts_are_not_relabelled_as_explicit(): void
    {
        $snapshot = ['reports' => [['source_key' => 'test', 'date' => '2026-10-07', 'species_counts' => [['species' => 'Bonito', 'retained' => 1, 'released' => 0]]]]];
        $count = app(ParsedCollectionSnapshot::class)->restore($snapshot)->tripReports[0]->speciesCounts[0];
        $this->assertSame(CatchQuantityBasis::LegacyUnclassified, $count->quantityBasis);
    }

    public function test_unknown_quantity_cannot_be_constructed_as_explicit_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ParsedSpeciesCountData('Bonito', null);
    }
}
