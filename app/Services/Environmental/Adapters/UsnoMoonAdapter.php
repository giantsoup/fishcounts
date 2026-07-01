<?php

namespace App\Services\Environmental\Adapters;

use App\DTOs\EnvironmentalFetchResult;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Services\Environmental\Contracts\EnvironmentalSourceAdapter;
use App\Services\Environmental\EnvironmentalHttpFetcher;
use Carbon\CarbonImmutable;

class UsnoMoonAdapter implements EnvironmentalSourceAdapter
{
    public function __construct(private readonly EnvironmentalHttpFetcher $fetcher) {}

    public function sourceKey(): string
    {
        return 'usno_moon';
    }

    public function fetchForDate(EnvironmentalSource $source, CarbonImmutable $date): EnvironmentalFetchResult
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $localDate = CarbonImmutable::parse($date->toDateString(), $timezone)->startOfDay();
        $offsetHours = (int) ($localDate->utcOffset() / 60);
        $coordinates = config('fish.conditions.latitude').','.config('fish.conditions.longitude');
        $path = '/api/rstt/oneday?date='.$localDate->toDateString().'&coords='.$coordinates.'&tz='.$offsetHours;

        return $this->fetcher->fetch($source, $date, $path);
    }

    public function observations(EnvironmentalSource $source, EnvironmentalPayload $payload): array
    {
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $date = CarbonImmutable::parse($payload->observed_date->toDateString(), $timezone);
        $observedAt = $date->startOfDay();
        $data = json_decode($payload->payload, true);
        $body = $data['properties']['data'] ?? null;

        if (! is_array($body)) {
            return [];
        }

        $observations = [];
        $phase = $body['curphase'] ?? null;
        $illumination = $this->percentValue($body['fracillum'] ?? null);

        if (is_string($phase) && $phase !== '') {
            $observations[] = $this->observation($source, $payload, $observedAt, 'moon_phase', null, null, $phase);
        }

        if ($illumination !== null) {
            $observations[] = $this->observation($source, $payload, $observedAt, 'moon_illumination_percent', $illumination, 'percent');
        }

        foreach ($body['moondata'] ?? [] as $moonEvent) {
            if (! is_array($moonEvent) || ! isset($moonEvent['phen'], $moonEvent['time'])) {
                continue;
            }

            $metric = match ($moonEvent['phen']) {
                'Rise' => 'moonrise',
                'Set' => 'moonset',
                default => null,
            };

            if ($metric === null || ! is_string($moonEvent['time'])) {
                continue;
            }

            $eventAt = CarbonImmutable::parse($date->toDateString().' '.$moonEvent['time'], $timezone);
            $observations[] = $this->observation($source, $payload, $eventAt, $metric, null, null, $moonEvent['time']);
        }

        return $observations;
    }

    private function percentValue(mixed $value): ?float
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = str_replace('%', '', (string) $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(EnvironmentalSource $source, EnvironmentalPayload $payload, CarbonImmutable $observedAt, string $metric, ?float $value, ?string $unit, ?string $textValue = null): array
    {
        return [
            'environmental_source_id' => $source->id,
            'environmental_payload_id' => $payload->id,
            'location_profile' => $source->location_profile,
            'observed_date' => $payload->observed_date->toDateString(),
            'observed_at' => $observedAt,
            'metric' => $metric,
            'value' => $value,
            'unit' => $unit,
            'text_value' => $textValue,
            'quality_flags' => ['verified' => true],
            'metadata' => ['station_id' => $source->station_id],
        ];
    }
}
