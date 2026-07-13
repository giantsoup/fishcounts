<?php

namespace App\Services\IssueTracking;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

final class GitHubAppTokenProvider
{
    public function token(): string
    {
        $clientId = (string) config('services.github_app.client_id');
        $installationId = (int) config('services.github_app.installation_id');
        $repository = (string) config('fish.github_issues.repository');

        if ($clientId === '' || $installationId < 1) {
            throw new RuntimeException('GitHub App credentials are not configured.');
        }

        return Cache::store('array')->remember(
            'github-app-installation-token-'.hash('sha256', $installationId.'|'.$repository),
            now()->addMinutes(50),
            function () use ($installationId, $repository): string {
                $response = Http::baseUrl((string) config('services.github_app.base_url'))
                    ->acceptJson()
                    ->withHeaders(['X-GitHub-Api-Version' => config('services.github_app.api_version')])
                    ->withToken($this->jwt())
                    ->connectTimeout((int) config('fish.github_issues.connect_timeout_seconds'))
                    ->timeout((int) config('fish.github_issues.timeout_seconds'))
                    ->retry([250, 1000], when: fn (Throwable $exception): bool => $this->retryable($exception))
                    ->post("/app/installations/{$installationId}/access_tokens", [
                        'repositories' => [str($repository)->after('/')->toString()],
                        'permissions' => ['issues' => 'write'],
                    ])
                    ->throw();

                $token = $response->json('token');
                if (! is_string($token) || $token === '') {
                    throw new RuntimeException('GitHub returned an invalid installation token.');
                }

                return $token;
            },
        );
    }

    /** @throws JsonException */
    private function jwt(): string
    {
        $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $this->encode([
            'iat' => now()->timestamp - 60,
            'exp' => now()->timestamp + 600,
            'iss' => (string) config('services.github_app.client_id'),
        ]);
        $unsigned = "{$header}.{$payload}";
        $privateKey = openssl_pkey_get_private($this->privateKey());

        if ($privateKey === false || ! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The configured GitHub App private key is invalid.');
        }

        return $unsigned.'.'.$this->base64UrlEncode($signature);
    }

    private function privateKey(): string
    {
        $path = (string) config('services.github_app.private_key_path');
        if ($path !== '') {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('The configured GitHub App private key file is not readable.');
            }

            $key = file_get_contents($path);

            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        $encoded = (string) config('services.github_app.private_key_base64');
        $key = base64_decode($encoded, true);

        if ($key === false || $key === '') {
            throw new RuntimeException('The GitHub App private key is not configured.');
        }

        return $key;
    }

    /** @param array<string, int|string> $value
     * @throws JsonException
     */
    private function encode(array $value): string
    {
        return $this->base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function retryable(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->status() === 429 || $exception->response->serverError()));
    }
}
