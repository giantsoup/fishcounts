<?php

namespace Tests\Feature;

use App\Enums\EnvironmentalLocationType;
use App\Enums\EnvironmentalSourceType;
use App\Models\EnvironmentalDailySummary;
use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function source(
        string $slug,
        string $name,
        EnvironmentalSourceType $sourceType,
        string $locationProfile = 'san_diego_bight',
        EnvironmentalLocationType $locationType = EnvironmentalLocationType::Local,
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
