<?php

namespace App\Services\Environmental;

use App\Models\EnvironmentalDailySummary;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class EnvironmentalConditionFormatter
{
    public function forDate(CarbonImmutable $date): ?string
    {
        $summary = EnvironmentalDailySummary::query()
            ->where('location_profile', config('fish.conditions.location_profile'))
            ->whereDate('observed_date', $date->toDateString())
            ->first();

        return $summary?->condition_summary;
    }

    public function weeklyLine(CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        $summaries = EnvironmentalDailySummary::query()
            ->where('location_profile', config('fish.conditions.location_profile'))
            ->whereDate('observed_date', '>=', $from->toDateString())
            ->whereDate('observed_date', '<=', $to->toDateString())
            ->orderBy('observed_date')
            ->get();

        if ($summaries->isEmpty()) {
            return null;
        }

        $moonPhases = $summaries->pluck('moon_phase')->filter()->unique()->values();
        $waterTemps = $this->numericValues($summaries, 'water_temp_f_avg');
        $swellDirections = $summaries->pluck('swell_direction_degrees_dominant')->filter(fn (mixed $value): bool => is_numeric($value));
        $parts = [];

        if ($moonPhases->isNotEmpty()) {
            $parts[] = 'moon '.$moonPhases->implode(' to ');
        }

        if ($waterTemps->isNotEmpty()) {
            $parts[] = 'avg water '.round((float) $waterTemps->average(), 1).' F';
        }

        if ($swellDirections->isNotEmpty()) {
            $parts[] = 'dominant swell '.$this->compassLabel((int) $swellDirections->mode()[0]);
        }

        if ($parts === []) {
            return 'Environmental data was collected this week, but no complete condition summary is available.';
        }

        return 'Weekly conditions: '.implode('; ', $parts).'.';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function formatAttributes(array $attributes): ?string
    {
        $parts = [];

        if (filled($attributes['moon_phase'] ?? null)) {
            $moon = (string) $attributes['moon_phase'];
            if (is_numeric($attributes['moon_illumination_percent'] ?? null)) {
                $moon .= ' '.round((float) $attributes['moon_illumination_percent']).'%';
            }
            $parts[] = 'moon '.$moon;
        }

        if (is_numeric($attributes['water_temp_f_avg'] ?? null)) {
            $parts[] = 'water '.round((float) $attributes['water_temp_f_avg'], 1).' F';
        }

        if (is_numeric($attributes['swell_height_ft_avg'] ?? null)) {
            $swell = 'swell '.round((float) $attributes['swell_height_ft_avg'], 1).' ft';
            if (is_numeric($attributes['swell_period_seconds_avg'] ?? null)) {
                $swell .= ' @ '.round((float) $attributes['swell_period_seconds_avg']).'s';
            }
            if (is_numeric($attributes['swell_direction_degrees_dominant'] ?? null)) {
                $swell .= ' '.$this->compassLabel((int) $attributes['swell_direction_degrees_dominant']);
            }
            $parts[] = $swell;
        } elseif (is_numeric($attributes['wave_height_ft_avg'] ?? null)) {
            $parts[] = 'waves '.round((float) $attributes['wave_height_ft_avg'], 1).' ft';
        }

        if (is_numeric($attributes['high_tide_height_ft'] ?? null) && $attributes['high_tide_at'] instanceof DateTimeInterface) {
            $parts[] = 'high '.$attributes['high_tide_at']->format('g:i A').' '.round((float) $attributes['high_tide_height_ft'], 1).' ft';
        }

        if (is_numeric($attributes['low_tide_height_ft'] ?? null) && $attributes['low_tide_at'] instanceof DateTimeInterface) {
            $parts[] = 'low '.$attributes['low_tide_at']->format('g:i A').' '.round((float) $attributes['low_tide_height_ft'], 1).' ft';
        }

        if ($parts === []) {
            return null;
        }

        $summary = implode('; ', $parts).'.';

        if (($attributes['is_partial'] ?? true) === true) {
            $summary .= ' Partial official data.';
        }

        return $summary;
    }

    /**
     * @param  Collection<int, EnvironmentalDailySummary>  $summaries
     * @return Collection<int, float>
     */
    private function numericValues(Collection $summaries, string $key): Collection
    {
        return $summaries
            ->pluck($key)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();
    }

    private function compassLabel(int $degrees): string
    {
        $directions = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $index = (int) round(($degrees % 360) / 22.5) % 16;

        return $directions[$index];
    }
}
