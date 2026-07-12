<?php

namespace App\Services\Environmental;

use App\Models\EnvironmentalDailySummary;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class EnvironmentalConditionFormatter
{
    public function forDate(CarbonImmutable $date, ?string $locationProfile = null): ?string
    {
        $summary = EnvironmentalDailySummary::query()
            ->where('location_profile', $locationProfile ?? config('fish.conditions.location_profile'))
            ->whereDate('observed_date', $date->toDateString())
            ->first();

        return $summary?->condition_summary;
    }

    public function weeklyLine(CarbonImmutable $from, CarbonImmutable $to, ?string $locationProfile = null): ?string
    {
        $summaries = EnvironmentalDailySummary::query()
            ->where('location_profile', $locationProfile ?? config('fish.conditions.location_profile'))
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
     * @return array{
     *     available: bool,
     *     has_readings: bool,
     *     heading: string,
     *     location_profile: string,
     *     location_label: string,
     *     source_note: ?string,
     *     date_display: string,
     *     water_temperature: ?string,
     *     swell: ?string,
     *     waves: ?string,
     *     moon: ?string,
     *     tides: ?string,
     *     is_partial: bool
     * }
     */
    public function detailsForDate(CarbonImmutable $date, string $locationProfile): array
    {
        $profile = config("fish.conditions.profiles.{$locationProfile}", []);
        $summary = EnvironmentalDailySummary::query()
            ->where('location_profile', $locationProfile)
            ->whereDate('observed_date', $date->toDateString())
            ->first();
        $locationType = (string) ($profile['location_type'] ?? 'local');
        $waterTemperature = $summary === null ? null : $this->temperatureReading($summary);
        $swell = $summary === null ? null : $this->swellReading($summary);
        $waves = $summary === null ? null : $this->waveReading($summary);
        $moon = $summary === null ? null : $this->moonReading($summary);
        $tides = $summary === null ? null : $this->tideReading($summary);

        return [
            'available' => $summary !== null,
            'has_readings' => collect([$waterTemperature, $swell, $waves, $moon, $tides])->contains(fn (?string $reading): bool => $reading !== null),
            'heading' => $locationType === 'local' ? 'Local conditions' : 'Offshore conditions',
            'location_profile' => $locationProfile,
            'location_label' => (string) ($profile['label'] ?? str($locationProfile)->replace('_', ' ')->headline()),
            'source_note' => filled($profile['source_note'] ?? null) ? (string) $profile['source_note'] : null,
            'date_display' => $date->format('M j, Y'),
            'water_temperature' => $waterTemperature,
            'swell' => $swell,
            'waves' => $waves,
            'moon' => $moon,
            'tides' => $tides,
            'is_partial' => $summary?->is_partial ?? false,
        ];
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

    private function temperatureReading(EnvironmentalDailySummary $summary): ?string
    {
        if (! is_numeric($summary->water_temp_f_avg)) {
            return null;
        }

        $reading = $this->decimal($summary->water_temp_f_avg).'°F average';

        if (is_numeric($summary->water_temp_f_min) && is_numeric($summary->water_temp_f_max)) {
            $minimum = $this->decimal($summary->water_temp_f_min);
            $maximum = $this->decimal($summary->water_temp_f_max);

            if ($minimum !== $maximum) {
                $reading .= " ({$minimum}–{$maximum}°F)";
            }
        }

        return $reading;
    }

    private function swellReading(EnvironmentalDailySummary $summary): ?string
    {
        if (! is_numeric($summary->swell_height_ft_avg)) {
            return null;
        }

        $reading = $this->decimal($summary->swell_height_ft_avg).' ft';

        if (is_numeric($summary->swell_period_seconds_avg)) {
            $reading .= ' at '.round((float) $summary->swell_period_seconds_avg).' sec';
        }

        if (is_numeric($summary->swell_direction_degrees_dominant)) {
            $reading .= ' · '.$this->compassLabel((int) $summary->swell_direction_degrees_dominant);
        }

        return $reading;
    }

    private function waveReading(EnvironmentalDailySummary $summary): ?string
    {
        if (! is_numeric($summary->wave_height_ft_avg)) {
            return null;
        }

        $reading = $this->decimal($summary->wave_height_ft_avg).' ft';

        if (is_numeric($summary->wave_period_seconds_avg)) {
            $reading .= ' at '.round((float) $summary->wave_period_seconds_avg).' sec';
        }

        if (is_numeric($summary->wave_direction_degrees_dominant)) {
            $reading .= ' · '.$this->compassLabel((int) $summary->wave_direction_degrees_dominant);
        }

        return $reading;
    }

    private function moonReading(EnvironmentalDailySummary $summary): ?string
    {
        if (! filled($summary->moon_phase)) {
            return null;
        }

        $reading = (string) $summary->moon_phase;

        if (is_numeric($summary->moon_illumination_percent)) {
            $reading .= ' · '.round((float) $summary->moon_illumination_percent).'% illuminated';
        }

        return $reading;
    }

    private function tideReading(EnvironmentalDailySummary $summary): ?string
    {
        $parts = [];
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');

        if (is_numeric($summary->high_tide_height_ft) && $summary->high_tide_at !== null) {
            $parts[] = 'High '.$summary->high_tide_at->timezone($timezone)->format('g:i A').' · '.$this->decimal($summary->high_tide_height_ft).' ft';
        }

        if (is_numeric($summary->low_tide_height_ft) && $summary->low_tide_at !== null) {
            $parts[] = 'Low '.$summary->low_tide_at->timezone($timezone)->format('g:i A').' · '.$this->decimal($summary->low_tide_height_ft).' ft';
        }

        return $parts === [] ? null : implode(' / ', $parts);
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 1, '.', '');
    }
}
