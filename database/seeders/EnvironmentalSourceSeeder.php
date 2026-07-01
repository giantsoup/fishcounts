<?php

namespace Database\Seeders;

use App\Enums\EnvironmentalSourceType;
use App\Models\EnvironmentalSource;
use Illuminate\Database\Seeder;

class EnvironmentalSourceSeeder extends Seeder
{
    public function run(): void
    {
        $locationProfile = (string) config('fish.conditions.location_profile', 'san_diego_bight');

        $sources = [
            [
                'name' => 'USNO Moon Phase',
                'slug' => 'usno_moon',
                'source_type' => EnvironmentalSourceType::Moon,
                'station_id' => null,
                'base_url' => 'https://aa.usno.navy.mil',
                'priority' => 10,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'USNO', 'product' => 'rstt/oneday'],
            ],
            [
                'name' => 'NOAA CO-OPS La Jolla',
                'slug' => 'noaa_coops_la_jolla',
                'source_type' => EnvironmentalSourceType::Tide,
                'station_id' => config('fish.conditions.stations.coops_la_jolla', '9410230'),
                'base_url' => 'https://api.tidesandcurrents.noaa.gov',
                'priority' => 20,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'NOAA CO-OPS', 'station_name' => 'La Jolla'],
            ],
            [
                'name' => 'NOAA CO-OPS San Diego',
                'slug' => 'noaa_coops_san_diego',
                'source_type' => EnvironmentalSourceType::WaterTemperature,
                'station_id' => config('fish.conditions.stations.coops_san_diego', '9410170'),
                'base_url' => 'https://api.tidesandcurrents.noaa.gov',
                'priority' => 30,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'NOAA CO-OPS', 'station_name' => 'San Diego'],
            ],
            [
                'name' => 'NDBC Mission Bay West',
                'slug' => 'ndbc_mission_bay_west',
                'source_type' => EnvironmentalSourceType::Wave,
                'station_id' => config('fish.conditions.stations.ndbc_mission_bay_west', '46258'),
                'base_url' => 'https://www.ndbc.noaa.gov',
                'priority' => 40,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'NOAA NDBC', 'station_name' => 'Mission Bay West'],
            ],
            [
                'name' => 'CDIP Mission Bay West',
                'slug' => 'cdip_mission_bay_west',
                'source_type' => EnvironmentalSourceType::Wave,
                'station_id' => config('fish.conditions.stations.cdip_mission_bay_west', '220p1'),
                'base_url' => 'https://thredds.cdip.ucsd.edu',
                'priority' => 50,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'CDIP', 'station_name' => 'Mission Bay West'],
            ],
        ];

        foreach ($sources as $source) {
            EnvironmentalSource::query()->updateOrCreate(
                ['slug' => $source['slug']],
                [
                    ...$source,
                    'location_profile' => $locationProfile,
                    'is_enabled' => in_array($source['slug'], config('fish.conditions.sources', []), true),
                    'supports_historical_dates' => false,
                ],
            );
        }
    }
}
