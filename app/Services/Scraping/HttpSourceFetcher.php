<?php

namespace App\Services\Scraping;

use App\DTOs\FetchResult;
use App\Models\ScrapeSource;
use App\Services\Scraping\Exceptions\SourceNotAllowedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HttpSourceFetcher
{
    public function __construct(private readonly HttpFactory $http) {}

    public function fetch(ScrapeSource $source, CarbonImmutable $date, string $path = '/'): FetchResult
    {
        $url = $this->buildUrl($source->base_url, $path);
        $this->assertAllowed($url);

        $lockKey = 'scrape-source-rate-limit:'.$source->id;
        Cache::lock($lockKey, max(1, $source->rate_limit_seconds + 5))->block(10, function () use ($source): void {
            $lastFetchKey = 'scrape-source-last-fetch:'.$source->id;
            $lastFetchAt = Cache::get($lastFetchKey);

            if (is_int($lastFetchAt)) {
                $elapsed = time() - $lastFetchAt;
                if ($elapsed < $source->rate_limit_seconds) {
                    sleep($source->rate_limit_seconds - $elapsed);
                }
            }

            Cache::put($lastFetchKey, time(), now()->addHour());
        });

        $response = $this->http
            ->withUserAgent((string) config('fish.scraping.user_agent'))
            ->accept('text/html,application/xhtml+xml,application/json,text/plain;q=0.9,*/*;q=0.8')
            ->timeout((int) config('fish.scraping.timeout_seconds'))
            ->connectTimeout((int) config('fish.scraping.connect_timeout_seconds'))
            ->retry([250, 1000, 2500], throw: false)
            ->get($url);

        return new FetchResult(
            url: $url,
            statusCode: $response->status(),
            contentType: $response->header('Content-Type'),
            body: $response->body(),
            fetchedAt: CarbonImmutable::now(),
            metadata: ['source_slug' => $source->slug, 'target_date' => $date->toDateString()],
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
        $allowedHosts = config('fish.scraping.allowed_hosts', []);

        if ($scheme !== 'https' || ! is_string($host) || ! in_array(Str::lower($host), $allowedHosts, true)) {
            throw new SourceNotAllowedException("Scrape URL is not allowlisted: {$url}");
        }
    }
}
