<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
class EnvironmentalDailySummary extends Model
{
    protected $attributes = [
        'is_partial' => true,
    ];

    protected function casts(): array
    {
        return [
            'observed_date' => 'date',
            'moon_illumination_percent' => 'decimal:2',
            'moonrise_at' => 'datetime',
            'moonset_at' => 'datetime',
            'high_tide_at' => 'datetime',
            'high_tide_height_ft' => 'decimal:3',
            'low_tide_at' => 'datetime',
            'low_tide_height_ft' => 'decimal:3',
            'water_temp_f_avg' => 'decimal:3',
            'water_temp_f_min' => 'decimal:3',
            'water_temp_f_max' => 'decimal:3',
            'wave_height_ft_avg' => 'decimal:3',
            'wave_height_ft_min' => 'decimal:3',
            'wave_height_ft_max' => 'decimal:3',
            'wave_period_seconds_avg' => 'decimal:3',
            'wave_period_seconds_min' => 'decimal:3',
            'wave_period_seconds_max' => 'decimal:3',
            'swell_height_ft_avg' => 'decimal:3',
            'swell_height_ft_min' => 'decimal:3',
            'swell_height_ft_max' => 'decimal:3',
            'swell_period_seconds_avg' => 'decimal:3',
            'swell_period_seconds_min' => 'decimal:3',
            'swell_period_seconds_max' => 'decimal:3',
            'coverage' => 'array',
            'is_partial' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }
}
