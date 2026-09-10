<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\Region;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use App\Services\Parsing\TripReportNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HalfDayDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private ScrapeSource $direct;

    private ScrapeSource $fallback;

    private Boat $boat;

    private Landing $landing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->direct = ScrapeSource::query()->create(['name' => 'Seaforth Sportfishing', 'slug' => 'seaforth_landing', 'source_type' => SourceType::Landing, 'base_url' => 'https://example.test', 'priority' => 10]);
        $this->fallback = ScrapeSource::query()->create(['name' => 'Party Boat Scores', 'slug' => 'sportfishingreport_landing_pages', 'source_type' => SourceType::Fallback, 'base_url' => 'https://example.test', 'priority' => 90]);
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $this->landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Seaforth Sportfishing', 'slug' => 'seaforth']);
        $this->boat = Boat::query()->create(['name' => 'New Seaforth', 'slug' => 'new-seaforth', 'landing_id' => $this->landing->id]);
        foreach (['1/2 Day', '1/2 Day AM', '1/2 Day PM', '1/2 Day Twilight', 'Full Day'] as $name) {
            TripType::query()->create(['name' => $name, 'slug' => Str::slug($name)]);
        }
    }

    #[DataProvider('productionPairs')]
    public function test_production_pairs_count_once_without_changing_source_evidence(string $date, string $boat, ?int $anglers, int $fallbackAnglers, array $counts): void
    {
        $this->boat->update(['name' => $boat]);
        $direct = $this->report($this->direct, '1/2 Day Twilight', $anglers, $counts, $date);
        $fallback = $this->report($this->fallback, '1/2 Day', $fallbackAnglers, array_reverse($counts, true), $date);
        $other = $this->report($this->fallback, '1/2 Day', 39, ['Yellowtail' => [4, 0], 'Bonito' => [17, 0]], $date);

        foreach ([1, 2] as $attempt) {
            app(TripReportNormalizer::class)->refreshPrimaryReports($date);
            $this->assertTrue($direct->fresh()->is_deduped_primary);
            $this->assertFalse($fallback->fresh()->is_deduped_primary);
            $this->assertTrue($other->fresh()->is_deduped_primary);
            $this->assertSame($anglers, $direct->fresh()->anglers);
            $this->assertSame($fallbackAnglers, $fallback->fresh()->anglers);
            $this->assertCount(count($counts), $fallback->speciesCounts);
        }

        $this->actingAs(User::factory()->create())->get(route('counts.index', ['from' => $date, 'to' => $date]))
            ->assertOk()->assertViewHas('summary', fn (array $summary): bool => $summary['trips'] === 2
                && $summary['retained'] === array_sum(array_column($counts, 0)) + 21);
    }

    /** @return array<string, array{string, string, ?int, int, array<string, array{int, int}>}> */
    public static function productionPairs(): array
    {
        return [
            'New Seaforth August 27' => ['2026-08-27', 'New Seaforth', 20, 20, ['Bonito' => [55, 0], 'Calico Bass' => [34, 0], 'Sculpin' => [2, 0], 'Rockfish' => [13, 0]]],
            'Sea Watch August 28' => ['2026-08-28', 'Sea Watch', null, 21, ['Bonito' => [61, 0], 'Calico Bass' => [25, 0]]],
        ];
    }

    #[DataProvider('conflictingReports')]
    public function test_conflicts_or_missing_identity_evidence_are_not_suppressed(array $counts, ?int $anglers, string $type, string $difference): void
    {
        $direct = $this->report($this->direct, '1/2 Day Twilight', 20, ['Bonito' => [55, 0], 'Calico Bass' => [34, 10]]);
        $fallback = $this->report($this->fallback, $type, $anglers, $counts);
        if ($difference === 'boat') {
            $fallback->update(['boat_id' => Boat::query()->create(['name' => 'Sea Watch', 'slug' => 'sea-watch'])->id]);
        } elseif ($difference === 'unknown boat') {
            $fallback->update(['boat_id' => null]);
        } elseif ($difference === 'landing') {
            $fallback->update(['landing_id' => Landing::query()->create(['region_id' => $this->landing->region_id, 'name' => 'Other', 'slug' => 'other'])->id]);
        } elseif ($difference === 'date') {
            $fallback->update(['trip_date' => '2026-08-28']);
        } elseif ($difference === 'empty') {
            $direct->speciesCounts()->delete();
        }
        app(TripReportNormalizer::class)->refreshPrimaryReportsForDates(['2026-08-27', '2026-08-28']);
        $this->assertTrue($direct->fresh()->is_deduped_primary);
        $this->assertTrue($fallback->fresh()->is_deduped_primary);
    }

    /** @return array<string, array{array<string, array{int, int}>, ?int, string, string}> */
    public static function conflictingReports(): array
    {
        $counts = ['Bonito' => [55, 0], 'Calico Bass' => [34, 10]];

        return [
            'retained differs' => [['Bonito' => [56, 0], 'Calico Bass' => [34, 10]], 20, '1/2 Day', ''],
            'released differs' => [['Bonito' => [55, 0], 'Calico Bass' => [34, 11]], 20, '1/2 Day', ''],
            'subset only' => [['Bonito' => [55, 0]], 20, '1/2 Day', ''],
            'empty catches' => [[], 20, '1/2 Day', 'empty'],
            'anglers conflict' => [$counts, 21, '1/2 Day', ''],
            'explicit zero conflicts' => [$counts, 0, '1/2 Day', ''],
            'different session' => [$counts, 20, '1/2 Day AM', ''],
            'different duration' => [$counts, 20, 'Full Day', ''],
            'different boat' => [$counts, 20, '1/2 Day', 'boat'],
            'unknown boat' => [$counts, 20, '1/2 Day', 'unknown boat'],
            'different landing' => [$counts, 20, '1/2 Day', 'landing'],
            'different date' => [$counts, 20, '1/2 Day', 'date'],
        ];
    }

    public function test_ambiguous_matches_in_either_direction_remain_primary(): void
    {
        $counts = ['Bonito' => [55, 0]];
        $am = $this->report($this->direct, '1/2 Day AM', 20, $counts);
        $twilight = $this->report($this->direct, '1/2 Day Twilight', 20, $counts);
        $fallback = $this->report($this->fallback, '1/2 Day', 20, $counts);
        app(TripReportNormalizer::class)->refreshPrimaryReports('2026-08-27');
        $this->assertSame(3, TripReport::query()->where('is_deduped_primary', true)->count());

        $am->speciesCounts()->delete();
        $am->delete();
        $secondFallback = $this->report($this->fallback, '1/2 Day', 20, $counts);
        app(TripReportNormalizer::class)->refreshPrimaryReports('2026-08-27');
        $this->assertTrue($twilight->fresh()->is_deduped_primary);
        $this->assertTrue($fallback->fresh()->is_deduped_primary);
        $this->assertTrue($secondFallback->fresh()->is_deduped_primary);
    }

    public function test_distinct_generic_trips_with_the_same_old_dedupe_key_remain_visible(): void
    {
        $this->report($this->fallback, '1/2 Day', 20, ['Bonito' => [55, 0]]);
        $this->report($this->fallback, '1/2 Day', 20, ['Bonito' => [61, 0]]);
        app(TripReportNormalizer::class)->refreshPrimaryReports('2026-08-27');
        $this->assertSame(2, TripReport::query()->where('is_deduped_primary', true)->count());
    }

    public function test_fallback_becomes_primary_again_when_direct_evidence_changes(): void
    {
        $direct = $this->report($this->direct, '1/2 Day Twilight', null, ['Bonito' => [61, 0]]);
        $fallback = $this->report($this->fallback, '1/2 Day', 21, ['Bonito' => [61, 0]]);
        app(TripReportNormalizer::class)->refreshPrimaryReports('2026-08-27');
        $this->assertFalse($fallback->fresh()->is_deduped_primary);
        $direct->speciesCounts()->update(['count' => 62]);
        app(TripReportNormalizer::class)->refreshPrimaryReports('2026-08-27');
        $this->assertTrue($fallback->fresh()->is_deduped_primary);
    }

    #[DataProvider('importOrders')]
    public function test_import_order_preserves_rows_and_suppresses_only_matching_catches(bool $directFirst, string $type): void
    {
        $direct = $this->payload($this->direct);
        $fallback = $this->payload($this->fallback);
        $normalizer = app(TripReportNormalizer::class);
        foreach ([$directFirst ? $direct : $fallback, $directFirst ? $fallback : $direct] as $payload) {
            $isDirect = $payload->scrape_source_id === $this->direct->id;
            $normalizer->replaceForPayload($payload, new ParsedFishCountCollection(collect([
                $this->parsedReport($payload, $isDirect ? $type : '1/2 Day', 55),
                ...($isDirect ? [] : [$this->parsedReport($payload, '1/2 Day', 61)]),
            ])), []);
            $normalizer->refreshPrimaryReports('2026-08-27');
        }
        $this->assertSame(3, TripReport::query()->count());
        $this->assertSame(2, TripReport::query()->where('is_deduped_primary', true)->count());
        $this->assertSame([61], $fallback->tripReports()->where('is_deduped_primary', true)->get()->flatMap(fn (TripReport $report) => $report->speciesCounts->pluck('count'))->all());
    }

    /** @return array<string, array{bool, string}> */
    public static function importOrders(): array
    {
        $cases = [];
        foreach (['1/2 Day', '1/2 Day AM', '1/2 Day PM', '1/2 Day Twilight'] as $type) {
            $cases[$type.' direct first'] = [true, $type];
            $cases[$type.' fallback first'] = [false, $type];
        }

        return $cases;
    }

    public function test_non_half_day_source_precedence_is_unchanged(): void
    {
        $direct = $this->report($this->direct, 'Full Day', 20, ['Bonito' => [55, 0]]);
        $fallback = $this->report($this->fallback, 'Full Day', 20, ['Bonito' => [55, 0]]);
        app(TripReportNormalizer::class)->refreshPrimaryReports('2026-08-27');
        $this->assertTrue($direct->fresh()->is_deduped_primary);
        $this->assertFalse($fallback->fresh()->is_deduped_primary);

        $payload = $this->payload($this->fallback);
        $this->assertSame(0, app(TripReportNormalizer::class)->replaceForPayload(
            $payload, new ParsedFishCountCollection(collect([$this->parsedReport($payload, 'Full Day', 55)])), [],
        ));
        $this->assertModelExists($direct);
        $this->assertSame(1, TripReport::query()->count());
    }

    public function test_cleanup_command_previews_then_applies_only_the_selected_date_without_external_work(): void
    {
        $this->report($this->direct, '1/2 Day Twilight', 20, ['Bonito' => [55, 0]]);
        $fallback = $this->report($this->fallback, '1/2 Day', 20, ['Bonito' => [55, 0]]);
        $this->report($this->direct, '1/2 Day Twilight', 20, ['Bonito' => [55, 0]], '2026-08-28');
        $otherDate = $this->report($this->fallback, '1/2 Day', 20, ['Bonito' => [55, 0]], '2026-08-28');
        Http::preventStrayRequests();
        Queue::fake();

        $this->artisan('fish:deduplicate-date', ['date' => '2026-08-27'])
            ->expectsOutputToContain('2 stored reports; 1 primary reports; 1 change(s).')->assertSuccessful();
        $this->assertTrue($fallback->fresh()->is_deduped_primary);
        $this->artisan('fish:deduplicate-date', ['date' => '2026-08-27', '--apply' => true])->assertSuccessful();
        $this->assertFalse($fallback->fresh()->is_deduped_primary);
        $this->assertTrue($otherDate->fresh()->is_deduped_primary);
        $this->artisan('fish:deduplicate-date', ['date' => '2026-08-27', '--apply' => true])
            ->expectsOutputToContain('0 change(s).')->assertSuccessful();
        $this->assertSame(4, TripReport::query()->count());
        $this->assertSame(1, $fallback->speciesCounts()->count());
        Queue::assertNothingPushed();
    }

    public function test_cleanup_command_rejects_invalid_dates_and_handles_empty_dates(): void
    {
        foreach (['2026-02-30', 'tomorrow', '08/27/2026'] as $date) {
            $this->artisan('fish:deduplicate-date', ['date' => $date, '--apply' => true])->assertFailed();
        }
        $this->artisan('fish:deduplicate-date', ['date' => '2026-08-27', '--apply' => true])
            ->expectsOutputToContain('0 stored reports; 0 primary reports; 0 change(s).')->assertSuccessful();
    }

    /** @param array<string, array{int, int}> $counts */
    private function report(ScrapeSource $source, string $type, ?int $anglers, array $counts, string $date = '2026-08-27'): TripReport
    {
        $report = TripReport::query()->create([
            'source_id' => $source->id, 'boat_id' => $this->boat->id, 'landing_id' => $this->landing->id,
            'trip_type_id' => TripType::query()->where('name', $type)->sole()->id, 'trip_date' => $date,
            'anglers' => $anglers, 'raw_boat_name' => $this->boat->name, 'raw_trip_type' => $type,
            'dedupe_key' => app(TripReportNormalizer::class)->dedupeKey($date, $this->boat->name, $type, $anglers),
            'source_confidence' => 100 - $source->priority,
        ]);
        foreach ($counts as $name => [$retained, $released]) {
            $species = Species::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
            $report->speciesCounts()->create(['species_id' => $species->id, 'count' => $retained, 'released_count' => $released]);
        }

        return $report;
    }

    private function payload(ScrapeSource $source): RawScrapePayload
    {
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => '2026-08-27']);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => '2026-08-27',
            'url' => $source->base_url, 'payload' => 'source evidence', 'payload_hash' => hash('sha256', $source->slug), 'fetched_at' => now(),
        ]);
    }

    private function parsedReport(RawScrapePayload $payload, string $type, int $count): ParsedTripReportData
    {
        $species = Species::query()->firstOrCreate(['slug' => 'bonito'], ['name' => 'Bonito']);

        return new ParsedTripReportData(
            sourceKey: $payload->scrapeSource->slug, tripDate: CarbonImmutable::parse('2026-08-27'), regionName: 'San Diego',
            landingName: $this->landing->name, boatName: $this->boat->name, tripTypeName: $type, anglers: 20,
            rawFishCountText: "{$count} Bonito", speciesCounts: [new ParsedSpeciesCountData('Bonito', $count, canonicalSpeciesId: $species->id)],
            canonicalBoatId: $this->boat->id, canonicalTripTypeId: TripType::query()->where('name', $type)->sole()->id,
        );
    }
}
