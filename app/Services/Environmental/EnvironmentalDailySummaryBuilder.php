<?php

namespace App\Services\Environmental;

use App\Enums\EnvironmentalLocationType;
use App\Models\EnvironmentalDailySummary;
use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class EnvironmentalDailySummaryBuilder
{
    public function __construct(private readonly EnvironmentalConditionFormatter $formatter) {}

    public function recompute(string $locationProfile, CarbonImmutable $date, bool $finalize = false): EnvironmentalDailySummary
    {
        $observations = EnvironmentalObservation::query()
            ->where('location_profile', $locationProfile)
            ->whereDate('observed_date', $date->toDateString())
            ->orderBy('observed_at')
            ->get();

        $summary = EnvironmentalDailySummary::query()
            ->where('location_profile', $locationProfile)
            ->whereDate('observed_date', $date->toDateString())
            ->first();
        $isFinalized = $finalize || ($summary?->finalized_at !== null && ! $summary->is_partial);
        $observationLocationType = $observations->first()?->location_type;
        $locationType = $observationLocationType instanceof EnvironmentalLocationType
            ? $observationLocationType->value
            : (EnvironmentalSource::query()->where('location_profile', $locationProfile)->value('location_type')
                ?? EnvironmentalLocationType::Local->value);

        $attributes = [
            'location_type' => $locationType,
            ...$this->moonAttributes($observations),
            ...$this->tideAttributes($observations),
            ...$this->rangeAttributes($observations, 'water_temperature', 'water_temp_f'),
            ...$this->rangeAttributes($observations, 'wave_height', 'wave_height_ft'),
            ...$this->rangeAttributes($observations, 'wave_period', 'wave_period_seconds'),
            ...$this->rangeAttributes($observations, 'swell_height', 'swell_height_ft'),
            ...$this->rangeAttributes($observations, 'swell_period', 'swell_period_seconds'),
            'wave_direction_degrees_dominant' => $this->dominantDirection($observations, 'wave_direction'),
            'swell_direction_degrees_dominant' => $this->dominantDirection($observations, 'swell_direction'),
            'coverage' => $this->coverage($observations),
            'is_partial' => ! $isFinalized,
            'finalized_at' => $isFinalized ? ($summary?->finalized_at ?? now()) : null,
        ];

        $attributes['condition_summary'] = $this->formatter->formatAttributes($attributes);

        $summary ??= new EnvironmentalDailySummary([
            'location_profile' => $locationProfile,
            'observed_date' => $date->toDateString(),
        ]);

        $summary->fill($attributes);
        $summary->save();

        return $summary;
    }

    /**
     * @param  Collection<int, EnvironmentalObservation>  $observations
     * @return array<string, mixed>
     */
    private function moonAttributes(Collection $observations): array
    {
        $phase = $observations->firstWhere('metric', 'moon_phase');
        $illumination = $observations->firstWhere('metric', 'moon_illumination_percent');
        $moonrise = $observations->firstWhere('metric', 'moonrise');
        $moonset = $observations->firstWhere('metric', 'moonset');

        return [
            'moon_phase' => $phase?->text_value,
            'moon_illumination_percent' => $illumination?->value,
            'moonrise_at' => $moonrise?->observed_at,
            'moonset_at' => $moonset?->observed_at,
        ];
    }

    /**
     * @param  Collection<int, EnvironmentalObservation>  $observations
     * @return array<string, mixed>
     */
    private function tideAttributes(Collection $observations): array
    {
        $high = $observations
            ->where('metric', 'tide_height')
            ->where('text_value', 'H')
            ->sortByDesc(fn (EnvironmentalObservation $observation): float => (float) $observation->value)
            ->first();
        $low = $observations
            ->where('metric', 'tide_height')
            ->where('text_value', 'L')
            ->sortBy(fn (EnvironmentalObservation $observation): float => (float) $observation->value)
            ->first();

        return [
            'high_tide_at' => $high?->observed_at,
            'high_tide_height_ft' => $high?->value,
            'low_tide_at' => $low?->observed_at,
            'low_tide_height_ft' => $low?->value,
        ];
    }

    /**
     * @param  Collection<int, EnvironmentalObservation>  $observations
     * @return array<string, mixed>
     */
    private function rangeAttributes(Collection $observations, string $metric, string $prefix): array
    {
        $values = $observations
            ->where('metric', $metric)
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();

        if ($values->isEmpty()) {
            return [
                "{$prefix}_avg" => null,
                "{$prefix}_min" => null,
                "{$prefix}_max" => null,
            ];
        }

        return [
            "{$prefix}_avg" => round((float) $values->average(), 3),
            "{$prefix}_min" => round((float) $values->min(), 3),
            "{$prefix}_max" => round((float) $values->max(), 3),
        ];
    }

    /**
     * @param  Collection<int, EnvironmentalObservation>  $observations
     */
    private function dominantDirection(Collection $observations, string $metric): ?int
    {
        $directions = $observations
            ->where('metric', $metric)
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => ((int) round((float) $value / 10) * 10) % 360)
            ->countBy()
            ->sortDesc();

        return $directions->isEmpty() ? null : (int) $directions->keys()->first();
    }

    /**
     * @param  Collection<int, EnvironmentalObservation>  $observations
     * @return array<string, mixed>
     */
    private function coverage(Collection $observations): array
    {
        $metrics = $observations->pluck('metric')->unique()->values();

        return [
            'metrics' => $metrics->all(),
            'observation_count' => $observations->count(),
            'has_moon' => $metrics->contains('moon_phase'),
            'has_tide' => $metrics->contains('tide_height'),
            'has_water_temperature' => $metrics->contains('water_temperature'),
            'has_wave' => $metrics->contains('wave_height'),
            'has_swell' => $metrics->contains('swell_height'),
            'source_ids' => $observations->pluck('environmental_source_id')->unique()->values()->all(),
        ];
    }
}
