<?php

namespace App\Services\Environmental\Adapters;

use App\DTOs\EnvironmentalFetchResult;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use App\Services\Environmental\Contracts\EnvironmentalSourceAdapter;
use App\Services\Environmental\EnvironmentalHttpFetcher;
use Carbon\CarbonImmutable;

class CdipStationAdapter implements EnvironmentalSourceAdapter
{
    public function __construct(private readonly EnvironmentalHttpFetcher $fetcher) {}

    public function sourceKey(): string
    {
        return 'cdip_station';
    }

    public function fetchForDate(EnvironmentalSource $source, CarbonImmutable $date): EnvironmentalFetchResult
    {
        $station = $source->station_id;
        $dataset = "/thredds/dodsC/cdip/realtime/{$station}_rt.nc";
        $dds = $this->fetcher->fetch($source, $date, "{$dataset}.dds");
        $waveLength = $this->dimensionLength($dds->body, 'waveTime');
        $sstLength = $this->dimensionLength($dds->body, 'sstTime');
        $waveStart = max(0, $waveLength - 96);
        $sstStart = max(0, $sstLength - 96);
        $query = "{$dataset}.ascii?waveTime[{$waveStart}:1:{$waveLength}],waveHs[{$waveStart}:1:{$waveLength}],waveTp[{$waveStart}:1:{$waveLength}],waveDp[{$waveStart}:1:{$waveLength}],sstTime[{$sstStart}:1:{$sstLength}],sstSeaSurfaceTemperature[{$sstStart}:1:{$sstLength}]";
        $ascii = $this->fetcher->fetch($source, $date, $query);

        return new EnvironmentalFetchResult(
            url: $ascii->url,
            statusCode: max($dds->statusCode ?? 0, $ascii->statusCode ?? 0) ?: null,
            contentType: 'application/json',
            body: json_encode(['dds' => $dds->body, 'ascii' => $ascii->body], JSON_THROW_ON_ERROR),
            fetchedAt: CarbonImmutable::now(),
            metadata: [
                'source_slug' => $source->slug,
                'target_date' => $date->toDateString(),
                'station_id' => $source->station_id,
                'urls' => ['dds' => $dds->url, 'ascii' => $ascii->url],
                'wave_start' => $waveStart,
                'sst_start' => $sstStart,
            ],
        );
    }

    public function observations(EnvironmentalSource $source, EnvironmentalPayload $payload): array
    {
        $body = json_decode($payload->payload, true);
        $ascii = (string) ($body['ascii'] ?? '');
        $waveTimes = $this->numericSeries($ascii, 'waveTime');
        $waveHeights = $this->numericSeries($ascii, 'waveHs');
        $wavePeriods = $this->numericSeries($ascii, 'waveTp');
        $waveDirections = $this->numericSeries($ascii, 'waveDp');
        $sstTimes = $this->numericSeries($ascii, 'sstTime');
        $sstValues = $this->numericSeries($ascii, 'sstSeaSurfaceTemperature');
        $observations = [];

        foreach ($waveTimes as $index => $epoch) {
            $observedAt = CarbonImmutable::createFromTimestamp((int) $epoch, 'UTC')
                ->setTimezone((string) config('fish.conditions.timezone', 'America/Los_Angeles'));

            foreach ([
                ['wave_height', $waveHeights[$index] ?? null, 'm', 'ft'],
                ['wave_period', $wavePeriods[$index] ?? null, 's', 's'],
                ['wave_direction', $waveDirections[$index] ?? null, 'degrees', 'degrees'],
                ['swell_height', $waveHeights[$index] ?? null, 'm', 'ft'],
                ['swell_period', $wavePeriods[$index] ?? null, 's', 's'],
                ['swell_direction', $waveDirections[$index] ?? null, 'degrees', 'degrees'],
            ] as [$metric, $rawValue, $rawUnit, $unit]) {
                $value = $this->convertedValue($rawValue, $rawUnit);

                if ($value === null) {
                    continue;
                }

                $observations[] = $this->observation($source, $payload, $observedAt, $metric, $value, $unit, ['raw_unit' => $rawUnit, 'verified' => true]);
            }
        }

        foreach ($sstTimes as $index => $epoch) {
            $value = $this->convertedValue($sstValues[$index] ?? null, 'C');

            if ($value === null) {
                continue;
            }

            $observations[] = $this->observation(
                $source,
                $payload,
                CarbonImmutable::createFromTimestamp((int) $epoch, 'UTC')->setTimezone((string) config('fish.conditions.timezone', 'America/Los_Angeles')),
                'water_temperature',
                $value,
                'F',
                ['raw_unit' => 'C', 'verified' => true],
            );
        }

        return collect($observations)
            ->filter(fn (array $observation): bool => $observation['observed_date'] === $payload->observed_date->toDateString())
            ->values()
            ->all();
    }

    private function dimensionLength(string $dds, string $dimension): int
    {
        if (preg_match('/'.$dimension.'\s*=\s*(\d+)/', $dds, $matches)) {
            return max(0, (int) $matches[1] - 1);
        }

        return 0;
    }

    /**
     * @return array<int, float>
     */
    private function numericSeries(string $ascii, string $name): array
    {
        if (! preg_match('/'.$name.'\[[^\]]+\]\s*\R([^\r\n]+)/', $ascii, $matches)) {
            return [];
        }

        return collect(explode(',', trim($matches[1])))
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => is_numeric($value))
            ->map(fn (string $value): float => (float) $value)
            ->values()
            ->all();
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
