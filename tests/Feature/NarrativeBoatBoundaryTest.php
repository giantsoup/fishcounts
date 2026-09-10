<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParseRawPayloadOptions;
use App\DTOs\RawPayloadData;
use App\Enums\ScrapeRunType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NarrativeBoatBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, int> $counts */
    #[DataProvider('reports')]
    public function test_narrative_boat_boundaries_survive_replay(string $sourceKey, string $paragraph, string $boat, string $trip, ?int $anglers, array $counts): void
    {
        $body = $sourceKey === 'seaforth_landing' ? "<ul><li>{$paragraph}</li></ul>" : "<p>{$paragraph}</p>";
        $payload = new RawPayloadData($sourceKey, CarbonImmutable::parse('2026-08-28'), 'https://example.test/counts', $body);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertCount(1, $parsed->tripReports);
        $report = $parsed->tripReports->sole();
        $this->assertSame($boat, $report->boatName);
        $this->assertSame($trip, $report->tripTypeName);
        $this->assertSame($anglers, $report->anglers);
        $this->assertSame($counts, collect($report->speciesCounts)->pluck('count', 'speciesName')->all());

        $this->seed(DatabaseSeeder::class);
        $landing = Landing::query()->where('name', $sourceKey === 'seaforth_landing' ? 'Seaforth Sportfishing' : "Fisherman's Landing")->firstOrFail();
        $canonicalBoat = Boat::query()->firstOrCreate(['slug' => Str::slug($boat)], ['name' => $boat, 'landing_id' => $landing->id]);
        $source = ScrapeSource::query()->where('slug', $sourceKey)->firstOrFail();
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => $payload->targetDate]);
        $stored = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => $payload->targetDate,
            'url' => $payload->url, 'payload' => $body, 'payload_hash' => hash('sha256', $body), 'fetched_at' => now(),
        ]);
        foreach ([1, 2] as $attempt) {
            app(ParseRawPayloadAction::class)->handleWithOptions($stored->id, ParseRawPayloadOptions::maintenance());
            $this->assertSame(1, $stored->tripReports()->count());
            $this->assertSame($canonicalBoat->id, $stored->tripReports()->sole()->boat_id);
            $this->assertFalse($stored->parserErrors()->whereNull('resolved_at')->where('raw_field', 'boat')->exists());
        }
    }

    /** @return array<string, array{string, string, string, string, ?int, array<string, int>}> */
    public static function reports(): array
    {
        return [
            '520 morning' => ['seaforth_landing', 'The New Seaforth checked in from their morning Half Day with 14 Yellowtail! They hooked more than they landed so be sure to come prepared with a 25-30# flyline setup and a 40# outfit for fishing yoyo jigs.', 'New Seaforth', '1/2 Day AM', null, ['Yellowtail' => 14]],
            '525 fished' => ['fishermans_landing', 'The Dolphin fished the Coronado Islands on a full day trip and returned with LIMITS of Bonito (150), 61 Yellowtail, 1 White Seabass, 10 Calico Bass, 1 Sheephead, 2 Lingcod for 30 anglers.', 'Dolphin', 'Full Day', 30, ['Bonito' => 150, 'Yellowtail' => 61, 'White Seabass' => 1, 'Calico Bass' => 10, 'Sheephead' => 1, 'Lingcod' => 2]],
            '538 progress' => ['seaforth_landing', 'The Polaris Supreme started off day three of their Three Day trip with limits of Bluefin tuna, 18 Yellowtail, 80 Dorado and 1 Shortblill spearfish.', 'Polaris Supreme', '3 Day', null, ['Yellowtail' => 18, 'Dorado' => 80, 'Shortblill Spearfish' => 1]],
            '538 possessive' => ['seaforth_landing', "The Sea Watch's Thursday night Twilight trip finished with 61 Bonito and 25 Calico bass.", 'Sea Watch', 'Twilight', null, ['Bonito' => 61, 'Calico Bass' => 25]],
            '567 called in' => ['fishermans_landing', 'The Pegasus on a 2 day trip called in with 32 Bluefin Tuna, 30 Yellowtail, 10 Dorado, 9 Yellowfin Tuna for 9 anglers.', 'Pegasus', '2 Day', 9, ['Bluefin Tuna' => 32, 'Yellowtail' => 30, 'Dorado' => 10, 'Yellowfin Tuna' => 9]],
            'valid apostrophe' => ['seaforth_landing', "The Fisherman's Friend finished their Full Day with 3 Yellowtail for 2 anglers.", "Fisherman's Friend", 'Full Day', 2, ['Yellowtail' => 3]],
        ];
    }

    public function test_adjacent_boats_keep_their_own_catches(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData('seaforth_landing', CarbonImmutable::parse('2026-08-28'), 'https://example.test/counts', '<ul><li>The New Seaforth checked in from their morning Half Day with 14 Yellowtail. The Polaris Supreme started off day three of their Three Day trip with 18 Yellowtail.</li></ul>'));
        $this->assertSame(['New Seaforth', 'Polaris Supreme'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame([14, 18], $parsed->tripReports->map(fn (ParsedTripReportData $report): int => $report->speciesCounts[0]->count)->all());
    }
}
