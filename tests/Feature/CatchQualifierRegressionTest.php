<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParseRawPayloadOptions;
use App\DTOs\RawPayloadData;
use App\Enums\ScrapeRunType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\SpeciesCount;
use App\Services\Parsing\GenericFishCountParser;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatchQualifierRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, int> $expected */
    #[DataProvider('reports')]
    public function test_source_counts_and_numeric_diagnostics_survive_replay(string $sourceKey, string $paragraph, string $boat, ?string $trip, ?int $anglers, array $expected): void
    {
        $body = $sourceKey === 'seaforth_landing' ? "<ul><li>{$paragraph}</li></ul>" : "<p>{$paragraph}</p>";
        $data = new RawPayloadData($sourceKey, CarbonImmutable::parse('2026-09-05'), 'https://example.test/counts', $body);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($data);
        $this->assertCount(1, $parsed->tripReports);
        $report = $parsed->tripReports->sole();
        $this->assertSame($expected, collect($report->speciesCounts)->pluck('count', 'speciesName')->all());
        $this->assertSame($boat, $report->boatName);
        $this->assertSame($trip, $report->tripTypeName);
        $this->assertSame($anglers, $report->anglers);

        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', $sourceKey)->firstOrFail();
        $landing = Landing::query()->where('name', $sourceKey === 'seaforth_landing' ? 'Seaforth Sportfishing' : "Fisherman's Landing")->firstOrFail();
        Boat::query()->firstOrCreate(['slug' => Str::slug($boat)], ['name' => $boat, 'landing_id' => $landing->id]);
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => $data->targetDate]);
        $stored = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => $data->targetDate,
            'url' => $data->url, 'payload' => $body, 'payload_hash' => hash('sha256', $body), 'fetched_at' => now(),
        ]);
        foreach ([1, 2] as $attempt) {
            app(ParseRawPayloadAction::class)->handleWithOptions($stored->id, ParseRawPayloadOptions::maintenance());
            $this->assertSame(1, $stored->tripReports()->count());
            $counts = $stored->tripReports()->sole()->speciesCounts()->with('species')->get()->mapWithKeys(fn (SpeciesCount $count): array => [$count->species->name => $count->count])->all();
            foreach ($expected as $species => $quantity) {
                if ($species !== 'Yellowtail And Stripped Marlin') {
                    $this->assertSame($quantity, $counts[$species] ?? null);
                }
            }
            $this->assertFalse($stored->parserErrors()->whereNull('resolved_at')->whereIn('error_type', ['unaccounted_numeric_tokens', 'prose_captured_as_entity'])->exists());
        }
    }

    /** @return array<string, array{string, string, string, ?string, ?int, array<string, int>}> */
    public static function reports(): array
    {
        return [
            '537 hash weight' => ['fishermans_landing', 'The Islander returned this morning 131 Bluefun Tuna (up to 150#) 11 Dorado, 54 Yellowtail and Stripped Marlin for their 3.5 day charter with 26 anglers.', 'Islander', '3.5 Day', 26, ['Bluefin Tuna' => 131, 'Dorado' => 11, 'Yellowtail And Stripped Marlin' => 54]],
            '538 terminal prose' => ['seaforth_landing', 'The Tribute ended their One Day trip with 43 Yellowfin tuna and 1 Dorado on the boat.', 'Tribute', '1 Day', null, ['Yellowfin Tuna' => 43, 'Dorado' => 1]],
            '567 location' => ['fishermans_landing', 'The Lucky B called in with 23 Yellowtail (fishing US waters) and 20 Bonito for their Fullday trip with 4 anglers.', 'Lucky B', 'Full Day', 4, ['Yellowtail' => 23, 'Bonito' => 20]],
            '585 limits before weight' => ['fishermans_landing', 'The Tomahawk called in with LIMITS of Bluefin Tuna (58) for (25-80 lbs) for 29 anglers.', 'Tomahawk', null, 29, ['Bluefin Tuna' => 58]],
            '585 subset' => ['fishermans_landing', 'The Pacific Queen called in with 2 day LIMITS of Bluefin Tuna (100) with 5 between 100-110 lbs (most are 40-80 lbs) for 25 anglers.', 'Pacific Queen', '2 Day', 25, ['Bluefin Tuna' => 100]],
        ];
    }

    public function test_additional_catches_and_count_parentheses_are_not_discarded(): void
    {
        $parser = app(GenericFishCountParser::class);
        $this->assertSame(105, $parser->parseSpeciesCounts('100 Bluefin Tuna and 5 Bluefin Tuna.')->sole()->count);
        $this->assertSame(58, $parser->parseSpeciesCounts('LIMITS of Bluefin Tuna (58).')->sole()->count);
        $this->assertSame(['Yellow' => 17], $parser->parseSpeciesCounts('17 Yellow.')->pluck('count', 'speciesName')->all());
        $this->assertSame(['Rockfish' => 23], $parser->parseSpeciesCounts('23 Rockfish (fishing Mexican waters).')->pluck('count', 'speciesName')->all());
    }
}
