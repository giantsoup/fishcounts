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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MissingTripDurationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('durations')]
    public function test_missing_and_unknown_trip_durations_remain_distinct(string $cell, ?string $expected): void
    {
        $body = <<<HTML
        <div class="panel"><h2>San Diego Fish Counts</h2>
          <div style="border-top: 1px solid #dedede;"><div class="row">
            <div class="col-md-4"><a>Lucky B Sportfishing</a><a>Fisherman's Landing</a></div>
            <div class="col-md-2">2 Anglers</div>
            <div class="col-md-2">{$cell}</div>
            <div class="col-md-1">&nbsp;</div>
            <div class="col-md-3">2 Bluefin Tuna, 10 Yellowtail</div>
          </div></div>
        </div>
        HTML;
        $data = new RawPayloadData('sportfishingreport_landing_pages', CarbonImmutable::parse('2026-08-25'), 'https://example.test/counts', $body);
        $report = app(SourceSpecificFishCountParser::class)->parse($data)->tripReports->sole();
        $this->assertSame($expected, $report->tripTypeName);
        $this->assertSame('Lucky B Sportfishing', $report->boatName);
        $this->assertSame(2, $report->anglers);
        $this->assertSame(['Bluefin Tuna' => 2, 'Yellowtail' => 10], collect($report->speciesCounts)->pluck('count', 'speciesName')->all());
        $this->assertSame('fallback', $report->metadata['source_role']);

        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', $data->sourceKey)->firstOrFail();
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        $boat = Boat::query()->firstOrCreate(['slug' => 'lucky-b-sportfishing'], ['name' => 'Lucky B Sportfishing', 'landing_id' => $landing->id]);
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => $data->targetDate]);
        $stored = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => $data->targetDate,
            'url' => $data->url, 'payload' => $body, 'payload_hash' => hash('sha256', $body), 'fetched_at' => now(),
        ]);
        foreach ([1, 2] as $attempt) {
            app(ParseRawPayloadAction::class)->handleWithOptions($stored->id, ParseRawPayloadOptions::maintenance());
            $this->assertSame(1, $stored->tripReports()->count());
            $persisted = $stored->tripReports()->sole();
            $this->assertSame($boat->id, $persisted->boat_id);
            $this->assertSame($expected, $persisted->raw_trip_type);
            $this->assertSame(12, $persisted->speciesCounts()->sum('count'));
            $errors = $stored->parserErrors()->whereNull('resolved_at')->where('error_type', 'unknown_trip_type_alias');
            if ($expected === 'Mystery Voyage') {
                $this->assertSame([$expected], $errors->pluck('raw_value')->all());
            } else {
                $this->assertFalse($errors->exists());
                if ($expected === null) {
                    $this->assertNull($persisted->trip_type_id);
                }
            }
        }
    }

    /** @return array<string, array{string, ?string}> */
    public static function durations(): array
    {
        return [
            'empty' => ['', null],
            'whitespace' => [" \n\t ", null],
            'html blank' => ['<span>&nbsp;</span>', null],
            'unknown duration' => ['Mystery Voyage Trip', 'Mystery Voyage'],
            'known duration' => ['Full Day Trip', 'Full Day'],
        ];
    }
}
