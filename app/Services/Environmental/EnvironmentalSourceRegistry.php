<?php

namespace App\Services\Environmental;

use App\Models\EnvironmentalSource;
use App\Services\Environmental\Adapters\CdipStationAdapter;
use App\Services\Environmental\Adapters\NdbcStationAdapter;
use App\Services\Environmental\Adapters\NoaaCoopsAdapter;
use App\Services\Environmental\Adapters\UsnoMoonAdapter;
use App\Services\Environmental\Contracts\EnvironmentalSourceAdapter;
use InvalidArgumentException;

class EnvironmentalSourceRegistry
{
    /** @var array<string, class-string<EnvironmentalSourceAdapter>> */
    private array $adapters = [
        'usno_moon' => UsnoMoonAdapter::class,
        'usno_moon_coronado_islands' => UsnoMoonAdapter::class,
        'noaa_coops_la_jolla' => NoaaCoopsAdapter::class,
        'noaa_coops_san_diego' => NoaaCoopsAdapter::class,
        'ndbc_mission_bay_west' => NdbcStationAdapter::class,
        'cdip_mission_bay_west' => CdipStationAdapter::class,
        'ndbc_point_loma_south' => NdbcStationAdapter::class,
        'cdip_point_loma_south' => CdipStationAdapter::class,
    ];

    public function forSource(EnvironmentalSource $source): EnvironmentalSourceAdapter
    {
        $adapterClass = $this->adapters[$source->slug] ?? null;

        if ($adapterClass === null) {
            throw new InvalidArgumentException("No environmental adapter registered for source [{$source->slug}].");
        }

        return app($adapterClass);
    }
}
