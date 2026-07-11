<?php

namespace Tests\Feature;

use App\Enums\EnvironmentalLocationType;
use App\Enums\EnvironmentalSourceType;
use App\Jobs\BackfillEnvironmentalSourceForDateJob;
use App\Jobs\FinalizeEnvironmentalConditionsForDateJob;
use App\Models\EnvironmentalDailySummary;
use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnvironmentalConditionAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_filter_environmental_condition_data(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source('noaa_coops_la_jolla', 'NOAA CO-OPS La Jolla', EnvironmentalSourceType::WaterTemperature);
        $otherSource = $this->source('ndbc_mission_bay_west', 'NDBC Mission Bay West', EnvironmentalSourceType::Wave);

        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-06-30',
            'moon_phase' => 'Waning Gibbous',
            'moon_illumination_percent' => 99,
            'water_temp_f_avg' => 68.7,
            'condition_summary' => 'moon Waning Gibbous 99%; water 68.7 F. Partial official data.',
            'coverage' => ['has_water_temperature' => true],
            'is_partial' => true,
        ]);
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-06-29',
            'moon_phase' => 'Full Moon',
            'condition_summary' => 'hidden previous day summary',
            'coverage' => [],
            'is_partial' => false,
            'finalized_at' => now(),
        ]);

        $payload = $this->payload($source, '2026-06-30', 'official-water-temperature-payload');
        $hiddenPayload = $this->payload($otherSource, '2026-06-30', 'hidden-wave-payload');

        EnvironmentalObservation::query()->create([
            'environmental_source_id' => $source->id,
            'environmental_payload_id' => $payload->id,
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-06-30',
            'observed_at' => '2026-06-30 12:00:00',
            'metric' => 'water_temperature',
            'value' => 68.7,
            'unit' => 'F',
            'quality_flags' => ['verified' => true],
            'metadata' => ['station_id' => '9410230'],
        ]);
        EnvironmentalObservation::query()->create([
            'environmental_source_id' => $otherSource->id,
            'environmental_payload_id' => $hiddenPayload->id,
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-06-30',
            'observed_at' => '2026-06-30 13:00:00',
            'metric' => 'wave_height',
            'value' => 3.2,
            'unit' => 'ft',
            'quality_flags' => ['verified' => true],
            'metadata' => ['station_id' => '46258'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.conditions.index', [
                'from' => '2026-06-30',
                'to' => '2026-06-30',
                'source_id' => $source->id,
                'metric' => 'water_temperature',
                'status' => 'partial',
            ]))
            ->assertOk()
            ->assertSee('Environmental conditions')
            ->assertSee('md:grid-cols-2 lg:grid-cols-6', false)
            ->assertSee('Location type')
            ->assertSee('San Diego')
            ->assertDontSee('San Diego Bight')
            ->assertDontSee('data-placeholder="All sources"', false)
            ->assertDontSee('data-placeholder="All metrics"', false)
            ->assertSee('NOAA CO-OPS La Jolla')
            ->assertSee('Local')
            ->assertSee('Waning Gibbous')
            ->assertSee('Water Temperature')
            ->assertSee('68.7 F')
            ->assertSee('1 obs')
            ->assertSee('1 payloads')
            ->assertSee('official-water-temperature-payload')
            ->assertDontSee('hidden previous day summary')
            ->assertDontSee('hidden-wave-payload');
    }

    public function test_admin_can_filter_environmental_condition_data_by_location_type_and_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $localSource = $this->source('ndbc_mission_bay_west', 'NDBC Mission Bay West', EnvironmentalSourceType::Wave);
        $islandsSource = $this->source(
            'ndbc_point_loma_south',
            'NDBC Point Loma South',
            EnvironmentalSourceType::Wave,
            'coronado_islands',
            EnvironmentalLocationType::Islands,
        );

        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'location_type' => EnvironmentalLocationType::Local->value,
            'observed_date' => '2026-06-30',
            'water_temp_f_avg' => 68.7,
            'condition_summary' => 'local San Diego summary',
            'coverage' => ['has_water_temperature' => true],
            'is_partial' => true,
        ]);
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'coronado_islands',
            'location_type' => EnvironmentalLocationType::Islands->value,
            'observed_date' => '2026-06-30',
            'water_temp_f_avg' => 66.8,
            'swell_height_ft_avg' => 3.1,
            'condition_summary' => 'Coronado Islands offshore summary',
            'coverage' => ['has_water_temperature' => true, 'has_swell' => true],
            'is_partial' => true,
        ]);

        $localPayload = $this->payload($localSource, '2026-06-30', 'local-payload');
        $islandsPayload = $this->payload($islandsSource, '2026-06-30', 'islands-payload');

        EnvironmentalObservation::query()->create([
            'environmental_source_id' => $localSource->id,
            'environmental_payload_id' => $localPayload->id,
            'location_profile' => 'san_diego_bight',
            'location_type' => EnvironmentalLocationType::Local->value,
            'observed_date' => '2026-06-30',
            'observed_at' => '2026-06-30 12:00:00',
            'metric' => 'water_temperature',
            'value' => 68.7,
            'unit' => 'F',
            'quality_flags' => ['verified' => true],
            'metadata' => ['station_id' => '46258'],
        ]);
        EnvironmentalObservation::query()->create([
            'environmental_source_id' => $islandsSource->id,
            'environmental_payload_id' => $islandsPayload->id,
            'location_profile' => 'coronado_islands',
            'location_type' => EnvironmentalLocationType::Islands->value,
            'observed_date' => '2026-06-30',
            'observed_at' => '2026-06-30 12:00:00',
            'metric' => 'water_temperature',
            'value' => 66.8,
            'unit' => 'F',
            'quality_flags' => ['verified' => true],
            'metadata' => ['station_id' => '46232'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.conditions.index', [
                'from' => '2026-06-30',
                'to' => '2026-06-30',
                'location_profile' => 'coronado_islands',
                'location_type' => EnvironmentalLocationType::Islands->value,
            ]))
            ->assertOk()
            ->assertSee('Coronado Islands')
            ->assertSee('Islands')
            ->assertSee('NDBC Point Loma South')
            ->assertSee('Coronado Islands offshore summary')
            ->assertSee('islands-payload')
            ->assertDontSee('local San Diego summary')
            ->assertDontSee('local-payload');
    }

    public function test_non_admin_cannot_view_environmental_condition_admin_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.conditions.index'))->assertForbidden();
    }

    public function test_environmental_condition_filters_validate_date_range(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.conditions.index', [
                'from' => '2026-07-01',
                'to' => '2026-06-30',
            ]))
            ->assertSessionHasErrors('from');
    }

    public function test_admin_conditions_page_explains_and_displays_the_backfill_workflow(): void
    {
        $admin = User::factory()->admin()->create();
        $historicalSource = $this->source(
            'noaa_coops_la_jolla',
            'NOAA CO-OPS La Jolla',
            EnvironmentalSourceType::Tide,
            supportsHistoricalDates: true,
        );
        $currentOnlySource = $this->source('ndbc_mission_bay_west', 'NDBC Mission Bay West', EnvironmentalSourceType::Wave);

        $this->actingAs($admin)
            ->get(route('admin.conditions.index'))
            ->assertOk()
            ->assertSee('Backfill historical conditions')
            ->assertSee('Available range: January 1, 2026 through today.')
            ->assertSee('summary is finalized only after all providers succeed')
            ->assertSee('Queue condition backfill')
            ->assertSee($historicalSource->name)
            ->assertDontSee($currentOnlySource->name.' · San Diego', false)
            ->assertSee('name="confirmed"', false)
            ->assertSee('min="2026-01-01"', false)
            ->assertSee(route('admin.conditions.backfills.store'), false);
    }

    public function test_admin_can_queue_a_condition_backfill_from_the_ui(): void
    {
        $admin = User::factory()->admin()->create();
        $moonSource = $this->source(
            'usno_moon',
            'USNO Moon Phase',
            EnvironmentalSourceType::Moon,
            supportsHistoricalDates: true,
        );
        $tideSource = $this->source(
            'noaa_coops_la_jolla',
            'NOAA CO-OPS La Jolla',
            EnvironmentalSourceType::Tide,
            supportsHistoricalDates: true,
        );
        $currentOnlySource = $this->source('ndbc_mission_bay_west', 'NDBC Mission Bay West', EnvironmentalSourceType::Wave);
        Queue::fake();

        $response = $this->actingAs($admin)->post(route('admin.conditions.backfills.store'), [
            'from_date' => '01/01/2026',
            'to_date' => '01/02/2026',
            'location_profile' => 'san_diego_bight',
            'confirmed' => '1',
        ]);

        $response
            ->assertRedirect(route('admin.conditions.index', [
                'from' => '2026-01-01',
                'to' => '2026-01-02',
                'location_profile' => 'san_diego_bight',
            ]))
            ->assertSessionHas('status', 'Submitted 4 planned historical provider collection(s). Overlapping work is serialized safely; refresh this page as summaries finish.');

        Queue::assertPushed(BackfillEnvironmentalSourceForDateJob::class, 2);
        Queue::assertPushed(BackfillEnvironmentalSourceForDateJob::class, function (BackfillEnvironmentalSourceForDateJob $job) use ($moonSource, $tideSource): bool {
            if ($job->date !== '2026-01-01') {
                return false;
            }

            $job->assertHasChain([
                new BackfillEnvironmentalSourceForDateJob($tideSource->id, '2026-01-01'),
                new FinalizeEnvironmentalConditionsForDateJob('san_diego_bight', '2026-01-01'),
            ]);

            return $job->environmentalSourceId === $moonSource->id;
        });
        Queue::assertPushed(BackfillEnvironmentalSourceForDateJob::class, function (BackfillEnvironmentalSourceForDateJob $job) use ($moonSource, $tideSource): bool {
            if ($job->date !== '2026-01-02') {
                return false;
            }

            $job->assertHasChain([
                new BackfillEnvironmentalSourceForDateJob($tideSource->id, '2026-01-02'),
                new FinalizeEnvironmentalConditionsForDateJob('san_diego_bight', '2026-01-02'),
            ]);

            return $job->environmentalSourceId === $moonSource->id;
        });
        Queue::assertNotPushed(BackfillEnvironmentalSourceForDateJob::class, fn (BackfillEnvironmentalSourceForDateJob $job): bool => $job->environmentalSourceId === $currentOnlySource->id);
    }

    public function test_condition_backfill_ui_rejects_unsafe_or_unconfirmed_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $this->source(
            'usno_moon',
            'USNO Moon Phase',
            EnvironmentalSourceType::Moon,
            supportsHistoricalDates: true,
        );
        Queue::fake();

        $this->actingAs($admin)
            ->from(route('admin.conditions.index'))
            ->post(route('admin.conditions.backfills.store'), [
                'from_date' => '2025-12-31',
                'to_date' => '2026-01-01',
                'location_profile' => 'san_diego_bight',
            ])
            ->assertRedirect(route('admin.conditions.index'))
            ->assertSessionHasErrors(['from_date', 'confirmed']);

        Queue::assertNothingPushed();
    }

    public function test_condition_backfill_ui_uses_the_conditions_timezone_for_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-10 17:30:00', 'America/Los_Angeles'));
        $admin = User::factory()->admin()->create();
        $this->source(
            'usno_moon',
            'USNO Moon Phase',
            EnvironmentalSourceType::Moon,
            supportsHistoricalDates: true,
        );
        Queue::fake();

        $this->actingAs($admin)
            ->from(route('admin.conditions.index'))
            ->post(route('admin.conditions.backfills.store'), [
                'from_date' => '2026-07-11',
                'to_date' => '2026-07-11',
                'location_profile' => 'san_diego_bight',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.conditions.index'))
            ->assertSessionHasErrors(['from_date', 'to_date']);

        Queue::assertNothingPushed();
    }

    public function test_non_admin_cannot_trigger_a_condition_backfill(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.conditions.backfills.store'), [
                'from_date' => '2026-01-01',
                'to_date' => '2026-01-01',
                'location_profile' => 'san_diego_bight',
                'confirmed' => '1',
            ])
            ->assertForbidden();
    }

    private function source(
        string $slug,
        string $name,
        EnvironmentalSourceType $sourceType,
        string $locationProfile = 'san_diego_bight',
        EnvironmentalLocationType $locationType = EnvironmentalLocationType::Local,
        bool $supportsHistoricalDates = false,
    ): EnvironmentalSource {
        return EnvironmentalSource::query()->create([
            'name' => $name,
            'slug' => $slug,
            'source_type' => $sourceType,
            'location_profile' => $locationProfile,
            'location_type' => $locationType->value,
            'station_id' => str($slug)->afterLast('_')->toString(),
            'base_url' => 'https://example.test',
            'rate_limit_seconds' => 0,
            'supports_historical_dates' => $supportsHistoricalDates,
        ]);
    }

    private function payload(EnvironmentalSource $source, string $date, string $body): EnvironmentalPayload
    {
        return EnvironmentalPayload::query()->create([
            'environmental_source_id' => $source->id,
            'location_profile' => $source->location_profile,
            'location_type' => $source->location_type->value,
            'observed_date' => $date,
            'url' => "https://example.test/{$source->slug}",
            'http_status' => 200,
            'content_type' => 'text/plain',
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => now(),
            'metadata' => ['source_slug' => $source->slug],
        ]);
    }
}
