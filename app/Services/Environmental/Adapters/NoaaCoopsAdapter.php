<?php

namespace App\Services\Environmental\Adapters;

use App\DTOs\EnvironmentalFetchResult;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Services\Environmental\Contracts\EnvironmentalSourceAdapter;
use App\Services\Environmental\EnvironmentalHttpFetcher;
use Carbon\CarbonImmutable;

class NoaaCoopsAdapter implements EnvironmentalSourceAdapter
{
    public function __construct(private readonly EnvironmentalHttpFetcher $fetcher) {}

    public function sourceKey(): string
    {
        return 'noaa_coops';
    }

    public function fetchForDate(EnvironmentalSource $source, CarbonImmutable $date): EnvironmentalFetchResult
    {
        $dateString = $date->format('Ymd');
        $station = $source->station_id;
        $paths = [
            'tide_predictions' => "/api/prod/datagetter?begin_date={$dateString}&end_date={$dateString}&station={$station}&product=predictions&datum=MLLW&time_zone=lst_ldt&units=english&interval=hilo&format=json",
            'water_temperature' => "/api/prod/datagetter?begin_date={$dateString}&end_date={$dateString}&station={$station}&product=water_temperature&time_zone=lst_ldt&units=english&format=json",
        ];
        $responses = [];
        $urls = [];
        $statusCodes = [];

        foreach ($paths as $key => $path) {
            $result = $this->fetcher->fetch($source, $date, $path);
            $responses[$key] = json_decode($result->body, true) ?? ['raw' => $result->body];
            $urls[$key] = $result->url;
            $statusCodes[$key] = $result->statusCode;
        }

        return new EnvironmentalFetchResult(
            url: implode(' ', $urls),
            statusCode: in_array(null, $statusCodes, true) ? null : max($statusCodes),
            contentType: 'application/json',
            body: json_encode(['responses' => $responses], JSON_THROW_ON_ERROR),
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
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $body = json_decode($payload->payload, true);
        $responses = $body['responses'] ?? [];
        $observations = [];

        foreach ($responses['tide_predictions']['predictions'] ?? [] as $prediction) {
            if (! is_array($prediction) || ! is_numeric($prediction['v'] ?? null) || ! in_array($prediction['type'] ?? null, ['H', 'L'], true)) {
                continue;
            }

            $observations[] = $this->observation(
                source: $source,
                payload: $payload,
                observedAt: CarbonImmutable::parse($prediction['t'], $timezone),
                metric: 'tide_height',
                value: (float) $prediction['v'],
                unit: 'ft',
                textValue: $prediction['type'],
            );
        }

        foreach ($responses['water_temperature']['data'] ?? [] as $row) {
            if (! is_array($row) || ! is_numeric($row['v'] ?? null) || ! $this->hasValidFlags($row['f'] ?? null)) {
                continue;
            }

            $observations[] = $this->observation(
                source: $source,
                payload: $payload,
                observedAt: CarbonImmutable::parse($row['t'], $timezone),
                metric: 'water_temperature',
                value: (float) $row['v'],
                unit: 'F',
                qualityFlags: ['raw_flags' => $row['f'], 'verified' => true],
            );
        }

        return $observations;
    }

    private function hasValidFlags(mixed $flags): bool
    {
        return is_string($flags) && collect(explode(',', $flags))->every(fn (string $flag): bool => $flag === '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(EnvironmentalSource $source, EnvironmentalPayload $payload, CarbonImmutable $observedAt, string $metric, ?float $value, ?string $unit, ?string $textValue = null, array $qualityFlags = ['verified' => true]): array
    {
        return [
            'environmental_source_id' => $source->id,
            'environmental_payload_id' => $payload->id,
            'location_profile' => $source->location_profile,
            'observed_date' => $observedAt->toDateString(),
            'observed_at' => $observedAt,
            'metric' => $metric,
            'value' => $value,
            'unit' => $unit,
            'text_value' => $textValue,
            'quality_flags' => $qualityFlags,
            'metadata' => ['station_id' => $source->station_id],
        ];
    }
}
