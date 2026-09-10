<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParseRawPayloadOptions;
use App\Enums\ScrapeRunType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpeciesAssumptionReplayTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, int> $expected */
    #[DataProvider('reports')]
    public function test_production_examples_replay_without_duplicate_or_ambiguous_species(string $sourceKey, string $boatName, string $body, array $expected): void
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', $sourceKey)->firstOrFail();
        $landing = Landing::query()->where('name', $source->name)->firstOrFail();
        Boat::query()->firstOrCreate(['slug' => Str::slug($boatName)], ['name' => $boatName, 'landing_id' => $landing->id]);
        Species::query()->firstOrCreate(['slug' => 'striped-marlin'], ['name' => 'Striped Marlin', 'is_active' => true]);
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => '2026-08-28']);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => '2026-08-28',
            'url' => 'https://example.test/counts', 'payload' => $body, 'payload_hash' => hash('sha256', $body), 'fetched_at' => now(),
        ]);
        foreach ([1, 2] as $attempt) {
            app(ParseRawPayloadAction::class)->handleWithOptions($payload->id, ParseRawPayloadOptions::maintenance());
            $this->assertSame(1, $payload->tripReports()->count());
            $counts = $payload->tripReports()->sole()->speciesCounts()->with('species')->get()->mapWithKeys(fn (SpeciesCount $count): array => [$count->species->name => $count->count]);
            foreach ($expected as $species => $quantity) {
                $this->assertSame($quantity, $counts->get($species));
            }
            $this->assertFalse($counts->has('Yellow'));
            $this->assertFalse($counts->has('Yellowtail And Stripped Marlin'));
            $this->assertFalse($payload->parserErrors()->whereNull('resolved_at')->where('error_type', 'unknown_species_alias')->exists());
            $this->assertSame($body, $payload->refresh()->payload);
        }
    }

    /** @return array<string, array{string, string, string, array<string, int>}> */
    public static function reports(): array
    {
        return [
            'Islander 537' => ['fishermans_landing', 'Islander', '<p>The Islander returned this morning 131 Bluefun Tuna (up to 150#) 11 Dorado, 54 Yellowtail and Stripped Marlin for their 3.5 day charter with 26 anglers.</p>', ['Dorado' => 11, 'Yellowtail' => 54, 'Striped Marlin' => 1]],
            'Aztec 538' => ['seaforth_landing', 'Aztec', '<ul><li>The Aztec 2 Day Trip finished with 92 Bluefin tuna(limits), 17 Yellow and 11 Dorado.</li></ul>', ['Bluefin Tuna' => 92, 'Dorado' => 11]],
        ];
    }
}
