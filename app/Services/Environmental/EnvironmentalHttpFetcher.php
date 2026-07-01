<?php

namespace App\Services\Environmental;

use App\DTOs\EnvironmentalFetchResult;
use App\Models\EnvironmentalSource;
use App\Services\Scraping\Exceptions\SourceNotAllowedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EnvironmentalHttpFetcher
{
    public function __construct(private readonly HttpFactory $http) {}

    public function fetch(EnvironmentalSource $source, CarbonImmutable $date, string $path): EnvironmentalFetchResult
    {
        return $this->fetchUrl($source, $date, $this->buildUrl($source->base_url, $path));
    }

    public function fetchUrl(EnvironmentalSource $source, CarbonImmutable $date, string $url): EnvironmentalFetchResult
    {
        $this->assertAllowed($url);
        $this->waitForRateLimit($source);

        $response = $this->http
            ->withUserAgent((string) config('fish.conditions.user_agent'))
            ->accept('application/json,text/plain,*/*;q=0.8')
            ->timeout((int) config('fish.conditions.timeout_seconds'))
            ->connectTimeout((int) config('fish.conditions.connect_timeout_seconds'))
            ->retry([250, 1000, 2500], throw: false)
            ->get($url);

        if ($response->failed()) {
            $response->throw();
        }

        return new EnvironmentalFetchResult(
            url: $url,
            statusCode: $response->status(),
            contentType: $response->header('Content-Type'),
            body: $response->body(),
            fetchedAt: CarbonImmutable::now(),
            metadata: [
                'source_slug' => $source->slug,
                'target_date' => $date->toDateString(),
                'station_id' => $source->station_id,
            ],
        );
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function assertAllowed(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $allowedHosts = config('fish.conditions.allowed_hosts', []);

        if ($scheme !== 'https' || ! is_string($host) || ! in_array(Str::lower($host), $allowedHosts, true)) {
            throw new SourceNotAllowedException("Environmental URL is not allowlisted: {$url}");
        }
    }

    private function waitForRateLimit(EnvironmentalSource $source): void
    {
        $lockKey = 'environmental-source-rate-limit:'.$source->id;

        Cache::lock($lockKey, max(1, $source->rate_limit_seconds + 5))->block(10, function () use ($source): void {
            $lastFetchKey = 'environmental-source-last-fetch:'.$source->id;
            $lastFetchAt = Cache::get($lastFetchKey);

            if (is_int($lastFetchAt)) {
                $elapsed = time() - $lastFetchAt;
                if ($elapsed < $source->rate_limit_seconds) {
                    sleep($source->rate_limit_seconds - $elapsed);
                }
            }

            Cache::put($lastFetchKey, time(), now()->addHour());
        });
    }
}
