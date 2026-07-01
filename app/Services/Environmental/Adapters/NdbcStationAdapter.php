<?php

namespace App\Services\Environmental\Adapters;

use App\DTOs\EnvironmentalFetchResult;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Services\Environmental\Contracts\EnvironmentalSourceAdapter;
use App\Services\Environmental\EnvironmentalHttpFetcher;
use Carbon\CarbonImmutable;

class NdbcStationAdapter implements EnvironmentalSourceAdapter
{
    public function __construct(private readonly EnvironmentalHttpFetcher $fetcher) {}

    public function sourceKey(): string
    {
        return 'ndbc_station';
    }

    public function fetchForDate(EnvironmentalSource $source, CarbonImmutable $date): EnvironmentalFetchResult
    {
        $station = $source->station_id;
        $paths = [
            'realtime' => "/data/realtime2/{$station}.txt",
            'latest' => "/data/latest_obs/{$station}.txt",
        ];
        $responses = [];
        $urls = [];
        $statusCodes = [];

        foreach ($paths as $key => $path) {
            $result = $this->fetcher->fetch($source, $date, $path);
            $responses[$key] = $result->body;
            $urls[$key] = $result->url;
            $statusCodes[$key] = $result->statusCode;
        }

        return new EnvironmentalFetchResult(
            url: implode(' ', $urls),
            statusCode: in_array(null, $statusCodes, true) ? null : max($statusCodes),
            contentType: 'application/json',
            body: json_encode(['responses' => $responses], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            fetchedAt: CarbonImmutable::now(),
            metadata: [
                'source_slug' => $source->slug,
                'target_date' => $date->toDateString(),
                'station_id' => $source->station_id,
                'urls' => $urls,
            ],
        );
    }

    public function observations(EnvironmentalSource $source, EnvironmentalPayload $payload): array
    {
        $body = json_decode($payload->payload, true);
        $responses = $body['responses'] ?? [];
        $observations = [
            ...$this->realtimeObservations($source, $payload, (string) ($responses['realtime'] ?? '')),
            ...$this->latestSwellObservations($source, $payload, (string) ($responses['latest'] ?? '')),
        ];

        return collect($observations)
            ->filter(fn (array $observation): bool => $observation['observed_date'] === $payload->observed_date->toDateString())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function realtimeObservations(EnvironmentalSource $source, EnvironmentalPayload $payload, string $text): array
    {
        $observations = [];

        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = preg_split('/\s+/', trim($line)) ?: [];
            if (count($columns) < 15) {
                continue;
            }

            $observedAt = CarbonImmutable::create(
                (int) $columns[0],
                (int) $columns[1],
                (int) $columns[2],
                (int) $columns[3],
                (int) $columns[4],
                0,
                'UTC',
            )->setTimezone((string) config('fish.conditions.timezone', 'America/Los_Angeles'));

            foreach ([
                ['wave_height', $columns[8], 'm', 'ft'],
                ['wave_period', $columns[9], 's', 's'],
                ['wave_direction', $columns[11], 'degrees', 'degrees'],
                ['water_temperature', $columns[14], 'C', 'F'],
            ] as [$metric, $rawValue, $rawUnit, $unit]) {
                $value = $this->convertedValue($rawValue, $rawUnit);

                if ($value === null) {
                    continue;
                }

                $observations[] = $this->observation($source, $payload, $observedAt, $metric, $value, $unit, ['raw_unit' => $rawUnit, 'verified' => true]);
            }
        }

        return $observations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestSwellObservations(EnvironmentalSource $source, EnvironmentalPayload $payload, string $text): array
    {
        $observedAt = $this->latestObservedAt($payload, $text);

        if (! preg_match('/Swell:\s*([\d.]+)\s*ft\s*\RPeriod:\s*([\d.]+)\s*sec\s*\RDirection:\s*([A-Z]+)/m', $text, $matches)) {
            return [];
        }

        return [
            $this->observation($source, $payload, $observedAt, 'swell_height', (float) $matches[1], 'ft', ['verified' => true, 'source_section' => 'latest_wave_summary']),
            $this->observation($source, $payload, $observedAt, 'swell_period', (float) $matches[2], 's', ['verified' => true, 'source_section' => 'latest_wave_summary']),
            $this->observation($source, $payload, $observedAt, 'swell_direction', $this->compassToDegrees($matches[3]), 'degrees', ['raw_direction' => $matches[3], 'verified' => true, 'source_section' => 'latest_wave_summary']),
        ];
    }

    private function latestObservedAt(EnvironmentalPayload $payload, string $text): CarbonImmutable
    {
        if (preg_match('/(\d{1,2}):(\d{2})\s*(am|pm)\s*P[DS]T\s*(\d{2})\/(\d{2})\/(\d{2})/i', $text, $matches)) {
            $year = 2000 + (int) $matches[6];
            $hour = (int) $matches[1] % 12;
            if (strtolower($matches[3]) === 'pm') {
                $hour += 12;
            }

            return CarbonImmutable::create($year, (int) $matches[4], (int) $matches[5], $hour, (int) $matches[2], 0, (string) config('fish.conditions.timezone', 'America/Los_Angeles'));
        }

        return CarbonImmutable::parse($payload->observed_date->toDateString(), (string) config('fish.conditions.timezone', 'America/Los_Angeles'))->startOfDay();
    }

    private function convertedValue(mixed $value, string $unit): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return match ($unit) {
            'm' => round((float) $value * 3.28084, 3),
            'C' => round(((float) $value * 9 / 5) + 32, 3),
            default => (float) $value,
        };
    }

    private function compassToDegrees(string $direction): float
    {
        return match ($direction) {
            'N' => 0.0,
            'NNE' => 22.5,
            'NE' => 45.0,
            'ENE' => 67.5,
            'E' => 90.0,
            'ESE' => 112.5,
            'SE' => 135.0,
            'SSE' => 157.5,
            'S' => 180.0,
            'SSW' => 202.5,
            'SW' => 225.0,
            'WSW' => 247.5,
            'W' => 270.0,
            'WNW' => 292.5,
            'NW' => 315.0,
            'NNW' => 337.5,
            default => 0.0,
        };
    }

    /**
     * @param  array<string, mixed>  $qualityFlags
     * @return array<string, mixed>
     */
    private function observation(EnvironmentalSource $source, EnvironmentalPayload $payload, CarbonImmutable $observedAt, string $metric, float $value, string $unit, array $qualityFlags): array
    {
        return [
            'environmental_source_id' => $source->id,
            'environmental_payload_id' => $payload->id,
            'location_profile' => $source->location_profile,
            'location_type' => $source->location_type->value,
            'observed_date' => $observedAt->toDateString(),
            'observed_at' => $observedAt,
            'metric' => $metric,
            'value' => $value,
            'unit' => $unit,
            'text_value' => null,
            'quality_flags' => $qualityFlags,
            'metadata' => ['station_id' => $source->station_id],
        ];
    }
}
