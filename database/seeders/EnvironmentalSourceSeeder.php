<?php

namespace Database\Seeders;

use App\Enums\EnvironmentalLocationType;
use App\Enums\EnvironmentalSourceType;
use App\Models\EnvironmentalSource;
use Illuminate\Database\Seeder;

class EnvironmentalSourceSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = collect(config('fish.conditions.profiles', []));
        if ($profiles->isEmpty()) {
            $profiles = collect([
                (string) config('fish.conditions.location_profile', 'san_diego_bight') => [
                    'label' => 'San Diego Bight',
                    'location_type' => EnvironmentalLocationType::Local->value,
                    'sources' => config('fish.conditions.sources', []),
                ],
            ]);
        }

        $sourceDefinitions = [
            'usno_moon' => [
                'name' => 'USNO Moon Phase',
                'slug' => 'usno_moon',
                'source_type' => EnvironmentalSourceType::Moon,
                'station_id' => null,
                'base_url' => 'https://aa.usno.navy.mil',
                'priority' => 10,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'USNO', 'product' => 'rstt/oneday'],
            ],
            'usno_moon_coronado_islands' => [
                'name' => 'USNO Moon Phase Coronado Islands',
                'slug' => 'usno_moon_coronado_islands',
                'source_type' => EnvironmentalSourceType::Moon,
                'station_id' => null,
                'base_url' => 'https://aa.usno.navy.mil',
                'priority' => 10,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'USNO', 'product' => 'rstt/oneday', 'shared_moon_context' => true],
            ],
            'noaa_coops_la_jolla' => [
                'name' => 'NOAA CO-OPS La Jolla',
                'slug' => 'noaa_coops_la_jolla',
                'source_type' => EnvironmentalSourceType::Tide,
                'station_id' => config('fish.conditions.stations.coops_la_jolla', '9410230'),
                'base_url' => 'https://api.tidesandcurrents.noaa.gov',
                'priority' => 20,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'NOAA CO-OPS', 'station_name' => 'La Jolla'],
            ],
            'noaa_coops_san_diego' => [
                'name' => 'NOAA CO-OPS San Diego',
                'slug' => 'noaa_coops_san_diego',
                'source_type' => EnvironmentalSourceType::WaterTemperature,
                'station_id' => config('fish.conditions.stations.coops_san_diego', '9410170'),
                'base_url' => 'https://api.tidesandcurrents.noaa.gov',
                'priority' => 30,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'NOAA CO-OPS', 'station_name' => 'San Diego'],
            ],
            'ndbc_mission_bay_west' => [
                'name' => 'NDBC Mission Bay West',
                'slug' => 'ndbc_mission_bay_west',
                'source_type' => EnvironmentalSourceType::Wave,
                'station_id' => config('fish.conditions.stations.ndbc_mission_bay_west', '46258'),
                'base_url' => 'https://www.ndbc.noaa.gov',
                'priority' => 40,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'NOAA NDBC', 'station_name' => 'Mission Bay West'],
            ],
            'cdip_mission_bay_west' => [
                'name' => 'CDIP Mission Bay West',
                'slug' => 'cdip_mission_bay_west',
                'source_type' => EnvironmentalSourceType::Wave,
                'station_id' => config('fish.conditions.stations.cdip_mission_bay_west', '220p1'),
                'base_url' => 'https://thredds.cdip.ucsd.edu',
                'priority' => 50,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'CDIP', 'station_name' => 'Mission Bay West'],
            ],
            'ndbc_point_loma_south' => [
                'name' => 'NDBC Point Loma South',
                'slug' => 'ndbc_point_loma_south',
                'source_type' => EnvironmentalSourceType::Wave,
                'station_id' => config('fish.conditions.stations.ndbc_point_loma_south', '46232'),
                'base_url' => 'https://www.ndbc.noaa.gov',
                'priority' => 60,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'NOAA NDBC', 'station_name' => 'Point Loma South', 'proxy_for' => 'Coronado Islands'],
            ],
            'cdip_point_loma_south' => [
                'name' => 'CDIP Point Loma South',
                'slug' => 'cdip_point_loma_south',
                'source_type' => EnvironmentalSourceType::Wave,
                'station_id' => config('fish.conditions.stations.cdip_point_loma_south', '191p1'),
                'base_url' => 'https://thredds.cdip.ucsd.edu',
                'priority' => 70,
                'rate_limit_seconds' => 5,
                'metadata' => ['provider' => 'CDIP', 'station_name' => 'Point Loma South', 'proxy_for' => 'Coronado Islands'],
            ],
        ];

        $profileBySource = $profiles
            ->flatMap(fn (array $profile, string $profileSlug): array => collect($profile['sources'] ?? [])
                ->mapWithKeys(fn (string $sourceSlug): array => [$sourceSlug => [
                    'location_profile' => $profileSlug,
                    'location_type' => $profile['location_type'] ?? EnvironmentalLocationType::Local->value,
                    'profile_label' => $profile['label'] ?? str($profileSlug)->replace('_', ' ')->headline()->toString(),
                    'latitude' => $profile['latitude'] ?? config('fish.conditions.latitude'),
                    'longitude' => $profile['longitude'] ?? config('fish.conditions.longitude'),
                ]])
                ->all());
        $enabledSources = config('fish.conditions.sources', []);

        foreach ($sourceDefinitions as $slug => $source) {
            $profile = $profileBySource[$slug] ?? null;

            if ($profile === null) {
                continue;
            }

            EnvironmentalSource::query()->updateOrCreate(
                ['slug' => $source['slug']],
                [
                    ...$source,
                    'location_profile' => $profile['location_profile'],
                    'location_type' => $profile['location_type'],
                    'is_enabled' => in_array($source['slug'], $enabledSources, true),
                    'supports_historical_dates' => false,
                    'metadata' => [
                        ...$source['metadata'],
                        'location_profile_label' => $profile['profile_label'],
                        'latitude' => $profile['latitude'],
                        'longitude' => $profile['longitude'],
                    ],
                ],
            );
        }
    }
}
