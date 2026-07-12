<?php

namespace Tests\Feature;

use App\Models\EnvironmentalDailySummary;
use App\Services\Environmental\EnvironmentalConditionFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentalConditionFormatterTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_are_scoped_to_the_requested_offshore_profile(): void
    {
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-07-10',
            'water_temp_f_avg' => 59.0,
            'coverage' => [],
        ]);
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'coronado_islands',
            'location_type' => 'islands',
            'observed_date' => '2026-07-10',
            'water_temp_f_avg' => 68.4,
            'water_temp_f_min' => 67.9,
            'water_temp_f_max' => 69.1,
            'swell_height_ft_avg' => 3.2,
            'swell_period_seconds_avg' => 14,
            'swell_direction_degrees_dominant' => 225,
            'moon_phase' => 'Waning Crescent',
            'moon_illumination_percent' => 18,
            'coverage' => [],
            'is_partial' => false,
        ]);

        $details = app(EnvironmentalConditionFormatter::class)->detailsForDate(
            CarbonImmutable::parse('2026-07-10'),
            'coronado_islands',
        );

        $this->assertTrue($details['available']);
        $this->assertSame('Offshore conditions', $details['heading']);
        $this->assertSame('Coronado Islands (Mexico)', $details['location_label']);
        $this->assertSame('68.4°F average (67.9–69.1°F)', $details['water_temperature']);
        $this->assertSame('3.2 ft at 14 sec · SW', $details['swell']);
        $this->assertSame('Waning Crescent · 18% illuminated', $details['moon']);
        $this->assertStringContainsString('Point Loma South', (string) $details['source_note']);
    }

    public function test_missing_offshore_data_does_not_fall_back_to_local_conditions(): void
    {
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-07-10',
            'water_temp_f_avg' => 59.0,
            'coverage' => [],
        ]);

        $details = app(EnvironmentalConditionFormatter::class)->detailsForDate(
            CarbonImmutable::parse('2026-07-10'),
            'coronado_islands',
        );

        $this->assertFalse($details['available']);
        $this->assertSame('Coronado Islands (Mexico)', $details['location_label']);
        $this->assertNull($details['water_temperature']);
    }

    public function test_local_details_format_waves_tides_and_partial_status(): void
    {
        EnvironmentalDailySummary::query()->create([
            'location_profile' => 'san_diego_bight',
            'observed_date' => '2026-07-10',
            'wave_height_ft_avg' => 2.4,
            'wave_period_seconds_avg' => 10,
            'wave_direction_degrees_dominant' => 180,
            'high_tide_at' => '2026-07-10 16:30:00',
            'high_tide_height_ft' => 5.2,
            'low_tide_at' => '2026-07-10 23:15:00',
            'low_tide_height_ft' => 0.8,
            'coverage' => [],
            'is_partial' => true,
        ]);

        $details = app(EnvironmentalConditionFormatter::class)->detailsForDate(
            CarbonImmutable::parse('2026-07-10'),
            'san_diego_bight',
        );

        $this->assertSame('Local conditions', $details['heading']);
        $this->assertSame('2.4 ft at 10 sec · S', $details['waves']);
        $this->assertStringContainsString('High', (string) $details['tides']);
        $this->assertStringContainsString('Low', (string) $details['tides']);
        $this->assertTrue($details['is_partial']);
    }
}
