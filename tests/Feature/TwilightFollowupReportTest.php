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
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwilightFollowupReportTest extends TestCase
{
    use RefreshDatabase;

    private const string PARAGRAPH = "The New Seaforth Twilight trips have had some fun fishing the last few days. They had 55 Bonito, 34 Calico Bass, 2 Sculpin and 13 Rockfish for their 20 anglers last night. It's a great way to beat the heat and get out on the water.";

    public function test_following_sentence_becomes_one_persisted_report(): void
    {
        $body = '<ul><li>'.self::PARAGRAPH.'</li></ul>';
        $data = $this->data($body);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($data);
        $this->assertCount(1, $parsed->tripReports);
        $report = $parsed->tripReports->sole();
        $this->assertSame('New Seaforth', $report->boatName);
        $this->assertSame('Twilight', $report->tripTypeName);
        $this->assertSame(20, $report->anglers);
        $this->assertSame('2026-08-27', $report->tripDate->toDateString());
        $this->assertSame(['Bonito' => 55, 'Calico Bass' => 34, 'Sculpin' => 2, 'Rockfish' => 13], collect($report->speciesCounts)->pluck('count', 'speciesName')->all());
        $this->assertStringContainsString('They had 55 Bonito', $report->rawFishCountText);

        $this->seed(DatabaseSeeder::class);
        $landing = Landing::query()->where('name', 'Seaforth Sportfishing')->firstOrFail();
        Boat::query()->firstOrCreate(['slug' => 'new-seaforth'], ['name' => 'New Seaforth', 'landing_id' => $landing->id]);
        $source = ScrapeSource::query()->where('slug', $data->sourceKey)->firstOrFail();
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => $data->targetDate]);
        $stored = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => $data->targetDate,
            'url' => $data->url, 'payload' => $body, 'payload_hash' => hash('sha256', $body), 'fetched_at' => now(),
        ]);
        foreach ([1, 2] as $attempt) {
            app(ParseRawPayloadAction::class)->handleWithOptions($stored->id, ParseRawPayloadOptions::maintenance());
            $this->assertSame(1, $stored->tripReports()->count());
            $persisted = $stored->tripReports()->sole();
            $this->assertSame('New Seaforth', $persisted->boat->name);
            $this->assertSame('1/2 Day Twilight', $persisted->tripType->name);
            $this->assertSame(20, $persisted->anglers);
            $this->assertSame(104, $persisted->speciesCounts()->sum('count'));
            $this->assertFalse($stored->parserErrors()->whereNull('resolved_at')->exists());
        }
    }

    public function test_context_stops_at_list_items_and_other_boats(): void
    {
        $parser = app(SourceSpecificFishCountParser::class);
        foreach ([
            '<ul><li>The New Seaforth Twilight trips have had fun fishing.</li><li>They had 55 Bonito for 20 anglers.</li></ul>',
            '<ul><li>The New Seaforth Twilight trips have had fun fishing. The Sea Watch was fishing too. They had 55 Bonito for 20 anglers.</li></ul>',
            '<ul><li>The New Seaforth Twilight trips have had fun fishing. Book now for 20 anglers.</li></ul>',
        ] as $body) {
            $body = str_replace('</ul>', '<li>The Sea Watch finished their Twilight trip with 2 Bonito for 2 anglers.</li></ul>', $body);
            $this->assertSame(['Sea Watch'], $parser->parse($this->data($body))->tripReports->pluck('boatName')->all());
        }
        $parsed = $parser->parse($this->data('<ul><li>'.self::PARAGRAPH.'</li><li>The Sea Watch finished their Twilight trip with 2 Bonito for 2 anglers.</li></ul>'));
        $this->assertSame(['New Seaforth', 'Sea Watch'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame([20, 2], $parsed->tripReports->pluck('anglers')->all());
    }

    private function data(string $body): RawPayloadData
    {
        return new RawPayloadData('seaforth_landing', CarbonImmutable::parse('2026-08-27'), 'https://example.test/counts', $body);
    }
}
