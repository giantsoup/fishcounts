<?php

namespace Tests\Feature;

use App\Enums\EnvironmentalLocationType;
use App\Enums\EnvironmentalSourceType;
use App\Jobs\CollectEnvironmentalSourceForDateJob;
use App\Models\EnvironmentalDailySummary;
use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Services\Environmental\EnvironmentalDailySummaryBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\EnvironmentalSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnvironmentalDataCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_environmental_data_command_queues_enabled_sources_for_date(): void
    {
        Queue::fake();
        $source = $this->source('usno_moon', EnvironmentalSourceType::Moon);
        $islandsSource = $this->source(
            'ndbc_point_loma_south',
            EnvironmentalSourceType::Wave,
            stationId: '46232',
            locationProfile: 'coronado_islands',
            locationType: EnvironmentalLocationType::Islands,
        );

        $this->artisan('fish:collect-environmental-data', [
            'date' => '2026-06-30',
            '--finalize' => true,
        ])->assertSuccessful();

        Queue::assertPushed(CollectEnvironmentalSourceForDateJob::class, fn (CollectEnvironmentalSourceForDateJob $job): bool => $job->environmentalSourceId === $source->id
            && $job->date === '2026-06-30'
            && $job->finalize);
        Queue::assertPushed(CollectEnvironmentalSourceForDateJob::class, fn (CollectEnvironmentalSourceForDateJob $job): bool => $job->environmentalSourceId === $islandsSource->id
            && $job->date === '2026-06-30'
            && $job->finalize);
    }

    public function test_collect_environmental_data_command_can_limit_sources_to_one_profile(): void
    {
        Queue::fake();
        $source = $this->source('usno_moon', EnvironmentalSourceType::Moon);
        $islandsSource = $this->source(
            'ndbc_point_loma_south',
            EnvironmentalSourceType::Wave,
            stationId: '46232',
            locationProfile: 'coronado_islands',
            locationType: EnvironmentalLocationType::Islands,
        );

        $this->artisan('fish:collect-environmental-data', [
            'date' => '2026-06-30',
            '--profile' => 'coronado_islands',
        ])->assertSuccessful();

        Queue::assertPushed(CollectEnvironmentalSourceForDateJob::class, fn (CollectEnvironmentalSourceForDateJob $job): bool => $job->environmentalSourceId === $islandsSource->id);
        Queue::assertNotPushed(CollectEnvironmentalSourceForDateJob::class, fn (CollectEnvironmentalSourceForDateJob $job): bool => $job->environmentalSourceId === $source->id);
    }

    public function test_collect_environmental_data_command_fails_when_no_enabled_sources_are_configured(): void
    {
        Queue::fake();

        $this->artisan('fish:collect-environmental-data', [
            'date' => '2026-06-30',
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_environmental_source_seeder_creates_local_and_islands_sources_idempotently(): void
    {
        $this->seed(EnvironmentalSourceSeeder::class);
        $this->seed(EnvironmentalSourceSeeder::class);

        $this->assertSame(1, EnvironmentalSource::query()->where('slug', 'ndbc_mission_bay_west')->count());
        $this->assertSame(1, EnvironmentalSource::query()->where('slug', 'ndbc_point_loma_south')->count());
        $this->assertSame(1, EnvironmentalSource::query()->where('slug', 'usno_moon_coronado_islands')->count());
        $this->assertSame(EnvironmentalLocationType::Local, EnvironmentalSource::query()->where('slug', 'ndbc_mission_bay_west')->firstOrFail()->location_type);
        $this->assertSame(EnvironmentalLocationType::Islands, EnvironmentalSource::query()->where('slug', 'ndbc_point_loma_south')->firstOrFail()->location_type);
        $this->assertSame(EnvironmentalLocationType::Islands, EnvironmentalSource::query()->where('slug', 'usno_moon_coronado_islands')->firstOrFail()->location_type);
        $this->assertSame('coronado_islands', EnvironmentalSource::query()->where('slug', 'cdip_point_loma_south')->firstOrFail()->location_profile);
        $this->assertSame(32.52, EnvironmentalSource::query()->where('slug', 'usno_moon_coronado_islands')->firstOrFail()->metadata['latitude']);
    }

    public function test_usno_moon_collection_stores_raw_payload_observations_and_daily_summary(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::response([
                'properties' => [
                    'data' => [
                        'curphase' => 'Waning Gibbous',
                        'fracillum' => '99%',
                        'moondata' => [
                            ['phen' => 'Set', 'time' => '05:06'],
                            ['phen' => 'Rise', 'time' => '20:00'],
                        ],
                    ],
                ],
            ]),
        ]);
        $source = $this->source('usno_moon', EnvironmentalSourceType::Moon, 'https://aa.usno.navy.mil');

        app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30', finalize: true), 'handle']);

        $summary = EnvironmentalDailySummary::query()->firstOrFail();

        $this->assertSame('Waning Gibbous', $summary->moon_phase);
        $this->assertSame('99.00', $summary->moon_illumination_percent);
        $this->assertFalse($summary->is_partial);
        $this->assertStringContainsString('moon Waning Gibbous 99%', (string) $summary->condition_summary);
        $this->assertSame(4, EnvironmentalObservation::query()->count());
        $this->assertSame(1, EnvironmentalPayload::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'date=2026-06-30')
            && str_contains($request->url(), 'tz=-7'));
    }

    public function test_coronado_islands_moon_collection_stores_moon_observations_on_islands_summary(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::response([
                'properties' => [
                    'data' => [
                        'curphase' => 'Waning Gibbous',
                        'fracillum' => '99%',
                        'moondata' => [
                            ['phen' => 'Set', 'time' => '05:06'],
                            ['phen' => 'Rise', 'time' => '20:00'],
                        ],
                    ],
                ],
            ]),
        ]);
        $source = $this->source(
            slug: 'usno_moon_coronado_islands',
            type: EnvironmentalSourceType::Moon,
            baseUrl: 'https://aa.usno.navy.mil',
            locationProfile: 'coronado_islands',
            locationType: EnvironmentalLocationType::Islands,
            metadata: ['latitude' => 32.52, 'longitude' => -117.43],
        );

        app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30', finalize: true), 'handle']);

        $summary = EnvironmentalDailySummary::query()->firstOrFail();

        $this->assertSame('coronado_islands', $summary->location_profile);
        $this->assertSame(EnvironmentalLocationType::Islands, $summary->location_type);
        $this->assertSame('Waning Gibbous', $summary->moon_phase);
        $this->assertSame('99.00', $summary->moon_illumination_percent);
        $this->assertFalse($summary->is_partial);
        $this->assertSame(4, EnvironmentalObservation::query()->where('location_type', EnvironmentalLocationType::Islands->value)->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'coords=32.52,-117.43'));
    }

    public function test_collection_is_idempotent_for_unchanged_payloads(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::response([
                'properties' => [
                    'data' => [
                        'curphase' => 'Waning Gibbous',
                        'fracillum' => '99%',
                        'moondata' => [],
                    ],
                ],
            ]),
        ]);
        $source = $this->source('usno_moon', EnvironmentalSourceType::Moon, 'https://aa.usno.navy.mil');

        app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30'), 'handle']);
        app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30'), 'handle']);

        $this->assertSame(1, EnvironmentalPayload::query()->count());
        $this->assertSame(2, EnvironmentalObservation::query()->count());
        $this->assertSame(1, EnvironmentalDailySummary::query()->count());
    }

    public function test_noaa_coops_collection_parses_tides_and_valid_water_temperature_flags(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'product=predictions')) {
                return Http::response([
                    'predictions' => [
                        ['t' => '2026-06-30 04:42', 'v' => '-0.611', 'type' => 'L'],
                        ['t' => '2026-06-30 21:47', 'v' => '5.915', 'type' => 'H'],
                    ],
                ]);
            }

            return Http::response([
                'data' => [
                    ['t' => '2026-06-30 00:00', 'v' => '68.7', 'f' => '0,0,0'],
                    ['t' => '2026-06-30 00:06', 'v' => '', 'f' => '1,1,1'],
                ],
            ]);
        });
        $source = $this->source('noaa_coops_la_jolla', EnvironmentalSourceType::Tide, 'https://api.tidesandcurrents.noaa.gov', '9410230');

        app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30'), 'handle']);

        $summary = EnvironmentalDailySummary::query()->firstOrFail();

        $this->assertSame('68.700', $summary->water_temp_f_avg);
        $this->assertSame('5.915', $summary->high_tide_height_ft);
        $this->assertSame('-0.611', $summary->low_tide_height_ft);
        $this->assertSame(3, EnvironmentalObservation::query()->count());
    }

    public function test_ndbc_and_cdip_collection_parse_wave_swell_and_water_temperature_data(): void
    {
        $epoch = CarbonImmutable::parse('2026-06-30 19:00:00', 'America/Los_Angeles')->timestamp;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($epoch) {
            if (str_contains($request->url(), 'realtime2/46258.txt')) {
                return Http::response(implode("\n", [
                    '#YY  MM DD hh mm WDIR WSPD GST  WVHT   DPD   APD MWD   PRES  ATMP  WTMP  DEWP  VIS PTDY  TIDE',
                    '2026 07 01 01 56  MM   MM   MM   1.2    18   6.4 194     MM    MM  20.7    MM   MM   MM    MM',
                    '2026 07 01 02 26  MM   MM   MM   MM     MM   MM  MM      MM    MM  MM      MM   MM   MM    MM',
                ]));
            }

            if (str_contains($request->url(), 'latest_obs/46258.txt')) {
                return Http::response(implode("\n", [
                    'Station 46258',
                    "32\xB0 45.0' N 117\xB0 30.1' W",
                    '6:56 pm PDT 06/30/26',
                    'Wave Summary',
                    '6:56 pm PDT 06/30/26',
                    'Swell: 2.3 ft',
                    'Period: 18.2 sec',
                    'Direction: SSW',
                ]));
            }

            if (str_ends_with($request->url(), '.dds')) {
                return Http::response('Int32 waveTime[waveTime = 2]; Int32 sstTime[sstTime = 2];');
            }

            return Http::response(implode("\n", [
                'Dataset { } cdip/realtime/220p1_rt.nc;',
                '---------------------------------------------',
                'waveTime[2]',
                "{$epoch}, ".($epoch + 1800),
                '',
                'waveHs[2]',
                '1.11, 1.12',
                '',
                'waveTp[2]',
                '18.18, 20.0',
                '',
                'waveDp[2]',
                '200.8, 201.0',
                '',
                'sstTime[2]',
                "{$epoch}, ".($epoch + 1800),
                '',
                'sstSeaSurfaceTemperature[2]',
                '22.4, 22.25',
            ]));
        });

        $ndbc = $this->source('ndbc_mission_bay_west', EnvironmentalSourceType::Wave, 'https://www.ndbc.noaa.gov', '46258');
        $cdip = $this->source('cdip_mission_bay_west', EnvironmentalSourceType::Wave, 'https://thredds.cdip.ucsd.edu', '220p1');

        app()->call([new CollectEnvironmentalSourceForDateJob($ndbc->id, '2026-06-30'), 'handle']);
        app()->call([new CollectEnvironmentalSourceForDateJob($cdip->id, '2026-06-30', finalize: true), 'handle']);

        $summary = EnvironmentalDailySummary::query()->firstOrFail();

        $this->assertFalse($summary->is_partial);
        $this->assertSame(200, $summary->swell_direction_degrees_dominant);
        $this->assertGreaterThan(3.5, (float) $summary->wave_height_ft_avg);
        $this->assertGreaterThan(71, (float) $summary->water_temp_f_avg);
        $this->assertStringContainsString('swell', (string) $summary->condition_summary);
    }

    public function test_point_loma_sources_create_separate_coronado_islands_daily_summary(): void
    {
        $epoch = CarbonImmutable::parse('2026-06-30 19:00:00', 'America/Los_Angeles')->timestamp;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($epoch) {
            if (str_contains($request->url(), 'realtime2/46232.txt')) {
                return Http::response(implode("\n", [
                    '#YY  MM DD hh mm WDIR WSPD GST  WVHT   DPD   APD MWD   PRES  ATMP  WTMP  DEWP  VIS PTDY  TIDE',
                    '2026 07 01 01 56  MM   MM   MM   1.5    16   6.4 205     MM    MM  19.8    MM   MM   MM    MM',
                ]));
            }

            if (str_contains($request->url(), 'latest_obs/46232.txt')) {
                return Http::response(implode("\n", [
                    'Station 46232',
                    '6:56 pm PDT 06/30/26',
                    'Wave Summary',
                    '6:56 pm PDT 06/30/26',
                    'Swell: 3.1 ft',
                    'Period: 16.0 sec',
                    'Direction: SW',
                ]));
            }

            if (str_ends_with($request->url(), '.dds')) {
                return Http::response('Int32 waveTime[waveTime = 1]; Int32 sstTime[sstTime = 1];');
            }

            return Http::response(implode("\n", [
                'Dataset { } cdip/realtime/191p1_rt.nc;',
                '---------------------------------------------',
                'waveTime[1]',
                (string) $epoch,
                '',
                'waveHs[1]',
                '1.2',
                '',
                'waveTp[1]',
                '16.2',
                '',
                'waveDp[1]',
                '220',
                '',
                'sstTime[1]',
                (string) $epoch,
                '',
                'sstSeaSurfaceTemperature[1]',
                '20.1',
            ]));
        });

        $ndbc = $this->source(
            'ndbc_point_loma_south',
            EnvironmentalSourceType::Wave,
            'https://www.ndbc.noaa.gov',
            '46232',
            'coronado_islands',
            EnvironmentalLocationType::Islands,
        );
        $cdip = $this->source(
            'cdip_point_loma_south',
            EnvironmentalSourceType::Wave,
            'https://thredds.cdip.ucsd.edu',
            '191p1',
            'coronado_islands',
            EnvironmentalLocationType::Islands,
        );

        app()->call([new CollectEnvironmentalSourceForDateJob($ndbc->id, '2026-06-30'), 'handle']);
        app()->call([new CollectEnvironmentalSourceForDateJob($cdip->id, '2026-06-30', finalize: true), 'handle']);

        $summary = EnvironmentalDailySummary::query()->firstOrFail();

        $this->assertSame('coronado_islands', $summary->location_profile);
        $this->assertSame(EnvironmentalLocationType::Islands, $summary->location_type);
        $this->assertGreaterThan(3.9, (float) $summary->wave_height_ft_avg);
        $this->assertGreaterThan(67, (float) $summary->water_temp_f_avg);
        $this->assertFalse($summary->is_partial);
    }

    public function test_failed_http_responses_do_not_replace_existing_observations_with_empty_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::response(['error' => 'temporary outage'], 503),
        ]);
        $source = $this->source('usno_moon', EnvironmentalSourceType::Moon, 'https://aa.usno.navy.mil');

        $this->expectException(RequestException::class);

        try {
            app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30'), 'handle']);
        } finally {
            $this->assertSame(0, EnvironmentalPayload::query()->count());
            $this->assertSame(0, EnvironmentalObservation::query()->count());
            $this->assertNotNull($source->fresh()->last_failure_at);
        }
    }

    public function test_partial_reruns_do_not_unfinalize_a_daily_summary(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'aa.usno.navy.mil/*' => Http::sequence()
                ->push([
                    'properties' => [
                        'data' => [
                            'curphase' => 'Full Moon',
                            'fracillum' => '100%',
                            'moondata' => [],
                        ],
                    ],
                ])
                ->push([
                    'properties' => [
                        'data' => [
                            'curphase' => 'Full Moon',
                            'fracillum' => '99%',
                            'moondata' => [],
                        ],
                    ],
                ]),
        ]);
        $source = $this->source('usno_moon', EnvironmentalSourceType::Moon, 'https://aa.usno.navy.mil');

        app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30', finalize: true), 'handle']);
        $finalizedAt = EnvironmentalDailySummary::query()->firstOrFail()->finalized_at;

        app()->call([new CollectEnvironmentalSourceForDateJob($source->id, '2026-06-30'), 'handle']);

        $summary = EnvironmentalDailySummary::query()->firstOrFail();

        $this->assertFalse($summary->is_partial);
        $this->assertTrue($summary->finalized_at->equalTo($finalizedAt));
        $this->assertSame('99.00', $summary->moon_illumination_percent);
    }

    public function test_daily_summaries_are_separate_by_location_profile_and_type_for_same_date(): void
    {
        $localSource = $this->source('ndbc_mission_bay_west', EnvironmentalSourceType::Wave, stationId: '46258');
        $islandsSource = $this->source(
            'ndbc_point_loma_south',
            EnvironmentalSourceType::Wave,
            stationId: '46232',
            locationProfile: 'coronado_islands',
            locationType: EnvironmentalLocationType::Islands,
        );

        EnvironmentalObservation::query()->create([
            'environmental_source_id' => $localSource->id,
            'location_profile' => 'san_diego_bight',
            'location_type' => EnvironmentalLocationType::Local->value,
            'observed_date' => '2026-06-30',
            'observed_at' => '2026-06-30 12:00:00',
            'metric' => 'water_temperature',
            'value' => 68.5,
            'unit' => 'F',
            'quality_flags' => ['verified' => true],
            'metadata' => ['station_id' => '46258'],
        ]);
        EnvironmentalObservation::query()->create([
            'environmental_source_id' => $islandsSource->id,
            'location_profile' => 'coronado_islands',
            'location_type' => EnvironmentalLocationType::Islands->value,
            'observed_date' => '2026-06-30',
            'observed_at' => '2026-06-30 12:00:00',
            'metric' => 'water_temperature',
            'value' => 66.2,
            'unit' => 'F',
            'quality_flags' => ['verified' => true],
            'metadata' => ['station_id' => '46232'],
        ]);

        app(EnvironmentalDailySummaryBuilder::class)->recompute('san_diego_bight', CarbonImmutable::parse('2026-06-30'));
        app(EnvironmentalDailySummaryBuilder::class)->recompute('coronado_islands', CarbonImmutable::parse('2026-06-30'));

        $this->assertSame(2, EnvironmentalDailySummary::query()->count());
        $this->assertSame('68.500', EnvironmentalDailySummary::query()->where('location_profile', 'san_diego_bight')->firstOrFail()->water_temp_f_avg);
        $this->assertSame('66.200', EnvironmentalDailySummary::query()->where('location_profile', 'coronado_islands')->firstOrFail()->water_temp_f_avg);
    }

    private function source(
        string $slug,
        EnvironmentalSourceType $type,
        string $baseUrl = 'https://example.test',
        ?string $stationId = null,
        string $locationProfile = 'san_diego_bight',
        EnvironmentalLocationType $locationType = EnvironmentalLocationType::Local,
        array $metadata = [],
    ): EnvironmentalSource {
        return EnvironmentalSource::query()->create([
            'name' => str($slug)->replace('_', ' ')->headline()->toString(),
            'slug' => $slug,
            'source_type' => $type,
            'location_profile' => $locationProfile,
            'location_type' => $locationType->value,
            'station_id' => $stationId,
            'base_url' => $baseUrl,
            'rate_limit_seconds' => 0,
            'metadata' => $metadata,
        ]);
    }
}
