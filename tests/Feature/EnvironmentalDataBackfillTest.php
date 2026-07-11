<?php

namespace Tests\Feature;

use App\Enums\EnvironmentalSourceType;
use App\Jobs\BackfillEnvironmentalSourceForDateJob;
use App\Jobs\CollectEnvironmentalSourceForDateJob;
use App\Jobs\FinalizeEnvironmentalConditionsForDateJob;
use App\Models\EnvironmentalDailySummary;
use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Services\Environmental\EnvironmentalBackfillDispatcher;
use App\Services\Environmental\EnvironmentalDailySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class EnvironmentalDataBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_queues_only_historical_sources_for_each_date_including_cutoff(): void
    {
        Queue::fake();
        $historicalSource = $this->source('usno_moon', supportsHistoricalDates: true);
        $currentOnlySource = $this->source('ndbc_mission_bay_west');

        $this->artisan('fish:backfill-environmental-data', [
            '--from' => '2026-01-01',
            '--to' => '2026-01-02',
        ])->assertSuccessful();

        Queue::assertPushed(BackfillEnvironmentalSourceForDateJob::class, 2);
        Queue::assertPushed(BackfillEnvironmentalSourceForDateJob::class, function (BackfillEnvironmentalSourceForDateJob $job) use ($historicalSource): bool {
            if ($job->date !== '2026-01-01') {
                return false;
            }

            $job->assertHasChain([
                new FinalizeEnvironmentalConditionsForDateJob('san_diego_bight', '2026-01-01'),
            ]);

            return $job->environmentalSourceId === $historicalSource->id;
        });
        Queue::assertPushed(BackfillEnvironmentalSourceForDateJob::class, function (BackfillEnvironmentalSourceForDateJob $job) use ($historicalSource): bool {
            if ($job->date !== '2026-01-02') {
                return false;
            }

            $job->assertHasChain([
                new FinalizeEnvironmentalConditionsForDateJob('san_diego_bight', '2026-01-02'),
            ]);

            return $job->environmentalSourceId === $historicalSource->id;
        });
        Queue::assertNotPushed(BackfillEnvironmentalSourceForDateJob::class, fn (BackfillEnvironmentalSourceForDateJob $job): bool => $job->environmentalSourceId === $currentOnlySource->id);
    }

    public function test_backfill_rejects_dates_before_january_2026_cutoff(): void
    {
        Queue::fake();
        $this->source('usno_moon', supportsHistoricalDates: true);

        $this->artisan('fish:backfill-environmental-data', [
            '--from' => '2025-12-31',
            '--to' => '2026-01-01',
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_backfill_rejects_an_inverted_date_range(): void
    {
        Queue::fake();
        $this->source('usno_moon', supportsHistoricalDates: true);

        $this->artisan('fish:backfill-environmental-data', [
            '--from' => '2026-01-02',
            '--to' => '2026-01-01',
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_manual_synchronous_backfill_collects_and_finalizes_historical_data_idempotently(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::response([
                'properties' => [
                    'data' => [
                        'curphase' => 'Waxing Gibbous',
                        'fracillum' => '93%',
                        'moondata' => [],
                    ],
                ],
            ]),
        ]);
        $this->source('usno_moon', baseUrl: 'https://aa.usno.navy.mil', supportsHistoricalDates: true);

        $arguments = [
            '--from' => '2026-01-01',
            '--to' => '2026-01-01',
            '--sync' => true,
        ];

        $this->artisan('fish:backfill-environmental-data', $arguments)->assertSuccessful();
        $this->artisan('fish:backfill-environmental-data', $arguments)->assertSuccessful();

        $summary = EnvironmentalDailySummary::query()->firstOrFail();

        $this->assertSame('2026-01-01', $summary->observed_date->toDateString());
        $this->assertSame('Waxing Gibbous', $summary->moon_phase);
        $this->assertFalse($summary->is_partial);
        $this->assertSame(1, EnvironmentalPayload::query()->count());
        $this->assertSame(2, EnvironmentalObservation::query()->count());
        $this->assertSame(1, EnvironmentalDailySummary::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'date=2026-01-01'));
    }

    public function test_synchronous_backfill_remains_partial_when_a_provider_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::response([
                'properties' => [
                    'data' => [
                        'curphase' => 'Waxing Gibbous',
                        'fracillum' => '93%',
                        'moondata' => [],
                    ],
                ],
            ]),
            'api.tidesandcurrents.noaa.gov/*' => Http::response(['error' => 'provider unavailable'], 503),
        ]);
        $this->source('usno_moon', baseUrl: 'https://aa.usno.navy.mil', supportsHistoricalDates: true);
        $this->source('noaa_coops_la_jolla', baseUrl: 'https://api.tidesandcurrents.noaa.gov', supportsHistoricalDates: true);

        try {
            app(EnvironmentalBackfillDispatcher::class)->dispatchRange(
                CarbonImmutable::parse('2026-01-01', 'America/Los_Angeles'),
                CarbonImmutable::parse('2026-01-01', 'America/Los_Angeles'),
                'san_diego_bight',
                synchronously: true,
            );

            $this->fail('The failed provider should abort synchronous backfill finalization.');
        } catch (RequestException) {
            $summary = EnvironmentalDailySummary::query()->firstOrFail();

            $this->assertTrue($summary->is_partial);
            $this->assertNull($summary->finalized_at);
            $this->assertSame('Waxing Gibbous', $summary->moon_phase);
        }
    }

    public function test_backfill_uses_the_conditions_timezone_for_the_latest_allowed_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-10 17:30:00', 'America/Los_Angeles'));
        Queue::fake();
        $this->source('usno_moon', supportsHistoricalDates: true);

        $this->artisan('fish:backfill-environmental-data', [
            '--from' => '2026-07-11',
            '--to' => '2026-07-11',
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_migration_enables_only_sources_with_historical_endpoints(): void
    {
        foreach (['usno_moon', 'usno_moon_coronado_islands', 'noaa_coops_la_jolla', 'noaa_coops_san_diego', 'ndbc_mission_bay_west'] as $slug) {
            $this->source($slug);
        }

        $migration = require database_path('migrations/2026_07_10_231726_enable_historical_environmental_sources.php');
        $migration->up();

        $this->assertSame(
            ['noaa_coops_la_jolla', 'noaa_coops_san_diego', 'usno_moon', 'usno_moon_coronado_islands'],
            EnvironmentalSource::query()->where('supports_historical_dates', true)->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertFalse(EnvironmentalSource::query()->where('slug', 'ndbc_mission_bay_west')->firstOrFail()->supports_historical_dates);
    }

    public function test_collection_and_finalization_jobs_share_the_same_profile_date_lock(): void
    {
        $source = $this->source('usno_moon', supportsHistoricalDates: true);
        $dailyJob = new CollectEnvironmentalSourceForDateJob($source->id, '2026-01-01');
        $backfillJob = new BackfillEnvironmentalSourceForDateJob($source->id, '2026-01-01');
        $finalizeJob = new FinalizeEnvironmentalConditionsForDateJob('san_diego_bight', '2026-01-01');

        $lockKeys = collect([$dailyJob, $backfillJob, $finalizeJob])
            ->map(fn (object $job): string => $job->middleware()[0]->getLockKey($job));

        $this->assertCount(1, $lockKeys->unique());
        $this->assertSame('laravel-queue-overlap:environmental-summary:san_diego_bight:2026-01-01', $lockKeys->first());
    }

    public function test_collection_rolls_back_observation_replacement_when_summary_recompute_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::response([
                'properties' => [
                    'data' => [
                        'curphase' => 'Waxing Gibbous',
                        'fracillum' => '93%',
                        'moondata' => [],
                    ],
                ],
            ]),
        ]);
        $source = $this->source('usno_moon', baseUrl: 'https://aa.usno.navy.mil', supportsHistoricalDates: true);
        $existingObservation = EnvironmentalObservation::query()->create([
            'environmental_source_id' => $source->id,
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-01-01',
            'observed_at' => '2026-01-01 00:00:00',
            'metric' => 'moon_phase',
            'text_value' => 'Existing phase',
            'quality_flags' => ['verified' => true],
            'metadata' => [],
        ]);
        $this->mock(EnvironmentalDailySummaryBuilder::class)
            ->shouldReceive('recompute')
            ->once()
            ->andThrow(new RuntimeException('Summary write failed.'));

        try {
            app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-01-01'), 'handle']);

            $this->fail('The summary failure should escape the collection job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Summary write failed.', $exception->getMessage());
            $this->assertTrue($existingObservation->is(EnvironmentalObservation::query()->sole()));
            $this->assertSame('Existing phase', EnvironmentalObservation::query()->sole()->text_value);
        }
    }

    private function source(
        string $slug,
        string $baseUrl = 'https://example.test',
        bool $supportsHistoricalDates = false,
    ): EnvironmentalSource {
        return EnvironmentalSource::query()->create([
            'name' => str($slug)->replace('_', ' ')->headline()->toString(),
            'slug' => $slug,
            'source_type' => EnvironmentalSourceType::Moon,
            'location_profile' => 'san_diego_bight',
            'base_url' => $baseUrl,
            'station_id' => str_starts_with($slug, 'noaa_coops_') ? '9410230' : null,
            'priority' => 10,
            'supports_historical_dates' => $supportsHistoricalDates,
            'rate_limit_seconds' => 0,
            'metadata' => ['latitude' => 32.75, 'longitude' => -117.25],
        ]);
    }
}
