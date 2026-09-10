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
use App\Services\Parsing\GenericFishCountParser;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParentheticalReleaseRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, int> $retained */
    #[DataProvider('reports')]
    public function test_retained_and_released_counts_are_persisted_on_each_replay(string $paragraph, string $trip, int $anglers, array $retained, int $released): void
    {
        $data = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-09-06'), 'https://example.test/counts', "<p>{$paragraph}</p>");
        $report = app(SourceSpecificFishCountParser::class)->parse($data)->tripReports->sole();
        $this->assertSame('Dolphin', $report->boatName);
        $this->assertSame($trip, $report->tripTypeName);
        $this->assertSame($anglers, $report->anglers);
        $this->assertSame('2026-09-06', $report->tripDate->toDateString());
        $this->assertSame($retained, collect($report->speciesCounts)->pluck('count', 'speciesName')->all());
        $this->assertSame($released, collect($report->speciesCounts)->firstWhere('speciesName', 'Calico Bass')->releasedCount);

        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', $data->sourceKey)->firstOrFail();
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        Boat::query()->firstOrCreate(['slug' => 'dolphin'], ['name' => 'Dolphin', 'landing_id' => $landing->id]);
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => $data->targetDate]);
        $stored = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => $data->targetDate,
            'url' => $data->url, 'payload' => $data->body, 'payload_hash' => hash('sha256', $data->body), 'fetched_at' => now(),
        ]);
        foreach ([1, 2] as $attempt) {
            app(ParseRawPayloadAction::class)->handleWithOptions($stored->id, ParseRawPayloadOptions::maintenance());
            $this->assertSame(1, $stored->tripReports()->count());
            $calico = $stored->tripReports()->sole()->speciesCounts()->whereHas('species', fn (Builder $query): Builder => $query->where('name', 'Calico Bass'))->sole();
            $this->assertSame($retained['Calico Bass'], $calico->count);
            $this->assertSame($released, $calico->released_count);
            $this->assertFalse($stored->parserErrors()->whereNull('resolved_at')->where('error_type', 'unaccounted_numeric_tokens')->exists());
        }
    }

    /** @return array<string, array{string, string, int, array<string, int>, int}> */
    public static function reports(): array
    {
        return [
            'AM' => ['The Dolphin AM trip caught 2 White Seabass (1 @ 40 lbs.), 6 Yellowtail (1 @ 35 lbs.), 211 Bonito, 41 Bullet Tuna, 33 Calico Bass (50 Calico Bass released), 19 Rockfish, and 7 Sandbass for 55 anglers.', '1/2 Day AM', 55, ['White Seabass' => 2, 'Yellowtail' => 6, 'Bonito' => 211, 'Bullet Tuna' => 41, 'Calico Bass' => 33, 'Rockfish' => 19, 'Sandbass' => 7], 50],
            'Twilight' => ['The Dolphin Twilight trip last night caught 62 Bonito, 29 Calico Bass (57 Calico Bass released), 20 Rockfish, 2 White Seabass, 2 Barracuda, and 1 Sandbass for 34 anglers.', 'Twilight', 34, ['Bonito' => 62, 'Calico Bass' => 29, 'Rockfish' => 20, 'White Seabass' => 2, 'Barracuda' => 2, 'Sandbass' => 1], 57],
        ];
    }

    public function test_parenthetical_species_controls_which_fish_were_released(): void
    {
        $parser = app(GenericFishCountParser::class);
        $same = $parser->parseSpeciesCounts('33 Calico Bass (50 calico   bass RELEASED), 2 Rockfish.');
        $this->assertSame(33, $same->firstWhere('speciesName', 'Calico Bass')->count);
        $this->assertSame(50, $same->firstWhere('speciesName', 'Calico Bass')->releasedCount);
        $different = $parser->parseSpeciesCounts('33 Calico Bass (50 Sand Bass released), 2 Rockfish.');
        $this->assertSame(['Calico Bass' => 33, 'Sand Bass' => 0, 'Rockfish' => 2], $different->pluck('count', 'speciesName')->all());
        $this->assertSame(['Calico Bass' => 0, 'Sand Bass' => 50, 'Rockfish' => 0], $different->pluck('releasedCount', 'speciesName')->all());
    }
}
