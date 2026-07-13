<?php

namespace App\Services\IssueTracking;

use App\Contracts\IssueTracking\IssueTracker;
use App\DTOs\ParserBugIssueData;
use App\DTOs\TrackedIssueData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class GitHubIssueTracker implements IssueTracker
{
    public function __construct(private readonly GitHubAppTokenProvider $tokenProvider) {}

    public function create(ParserBugIssueData $issue): TrackedIssueData
    {
        $request = $this->request($this->tokenProvider->token());
        $labels = [];

        foreach ($issue->requiredLabels as $label) {
            $this->ensureLabel($request, $label);
            $labels[] = $label;
        }

        foreach ($issue->optionalLabels as $label) {
            if ($this->labelExists($request, $label)) {
                $labels[] = $label;
            }
        }

        $existing = $this->findExisting($request, $issue->body);
        if ($existing !== null) {
            return $existing;
        }

        $response = $request
            ->post($this->repositoryPath('/issues'), [
                'title' => $issue->title,
                'body' => $issue->body,
                'labels' => $labels,
                'assignees' => $issue->assignees,
            ])
            ->throw();

        return $this->trackedIssue($response);
    }

    public function get(int $issueNumber): TrackedIssueData
    {
        $response = $this->retryableRequest($this->tokenProvider->token())
            ->get($this->repositoryPath("/issues/{$issueNumber}"))
            ->throw();

        return $this->trackedIssue($response);
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('services.github_app.base_url'))
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => config('services.github_app.api_version')])
            ->withToken($token)
            ->connectTimeout((int) config('fish.github_issues.connect_timeout_seconds'))
            ->timeout((int) config('fish.github_issues.timeout_seconds'));
    }

    private function ensureLabel(PendingRequest $request, string $label): void
    {
        if ($this->labelExists($request, $label)) {
            return;
        }

        $settings = config("fish.github_issues.required_labels.{$label}");
        if (! is_array($settings)) {
            throw new RuntimeException('The configured GitHub issue label is invalid.');
        }

        $response = $request->post($this->repositoryPath('/labels'), [
            'name' => $label,
            'color' => $settings['color'],
            'description' => $settings['description'],
        ]);

        if ($response->status() === 422 && $this->labelExists($request, $label)) {
            return;
        }

        $response->throw();
    }

    private function labelExists(PendingRequest $request, string $label): bool
    {
        $response = (clone $request)
            ->retry([250, 1000], when: fn (Throwable $exception): bool => $this->retryable($exception), throw: false)
            ->get($this->repositoryPath('/labels/'.rawurlencode($label)));

        if ($response->notFound()) {
            return false;
        }

        $response->throw();

        return true;
    }

    private function findExisting(PendingRequest $request, string $body): ?TrackedIssueData
    {
        if (preg_match('/parser-bug-signature: ([a-f0-9]{64})/', $body, $matches) !== 1) {
            throw new RuntimeException('The parser-bug issue body has no stable signature.');
        }

        $repository = (string) config('fish.github_issues.repository');
        $response = (clone $request)
            ->retry([250, 1000], when: fn (Throwable $exception): bool => $this->retryable($exception), throw: false)
            ->get('/search/issues', [
                'q' => 'repo:'.$repository.' is:issue "parser-bug-signature: '.$matches[1].'"',
                'per_page' => 10,
            ])
            ->throw();

        foreach ($response->json('items', []) as $item) {
            if (is_array($item) && str_contains((string) ($item['body'] ?? ''), "parser-bug-signature: {$matches[1]}")) {
                return $this->trackedIssueData($item);
            }
        }

        return null;
    }

    private function trackedIssue(Response $response): TrackedIssueData
    {
        return $this->trackedIssueData($response->json());
    }

    /** @param array<string, mixed> $issue */
    private function trackedIssueData(array $issue): TrackedIssueData
    {
        $number = $issue['number'] ?? null;
        $url = $issue['html_url'] ?? null;
        $state = $issue['state'] ?? null;

        $expectedUrl = is_int($number)
            ? 'https://github.com/'.config('fish.github_issues.repository')."/issues/{$number}"
            : null;

        if (! is_int($number)
            || $number < 1
            || ! is_string($url)
            || $url !== $expectedUrl
            || ! in_array($state, ['open', 'closed'], true)) {
            throw new RuntimeException('GitHub returned an invalid issue response.');
        }

        return new TrackedIssueData($number, $url, $state);
    }

    private function repositoryPath(string $path): string
    {
        $repository = (string) config('fish.github_issues.repository');
        if ($repository !== 'giantsoup/fishcounts') {
            throw new RuntimeException('The GitHub issue repository must remain fixed to giantsoup/fishcounts.');
        }

        return "/repos/{$repository}{$path}";
    }

    private function retryableRequest(string $token): PendingRequest
    {
        return $this->request($token)
            ->retry([250, 1000], when: fn (Throwable $exception): bool => $this->retryable($exception), throw: false);
    }

    private function retryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $response = $exception->response;

        return $response->status() === 429
            || $response->serverError()
            || ($response->status() === 403
                && ($response->hasHeader('Retry-After')
                    || $response->header('X-RateLimit-Remaining') === '0'
                    || str_contains(strtolower($response->body()), 'secondary rate limit')));
    }
}
