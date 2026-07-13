<?php

namespace Tests\Feature;

use App\DTOs\ParserBugIssueData;
use App\Services\IssueTracking\GitHubAppTokenProvider;
use App\Services\IssueTracking\GitHubIssueTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GitHubIssueTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fish.github_issues.repository', 'giantsoup/fishcounts');
        config()->set('fish.github_issues.required_labels', [
            'parser-bug' => ['color' => 'd73a4a', 'description' => 'Parser defect.'],
            'llm-detected' => ['color' => '7057ff', 'description' => 'Validated AI diagnostic.'],
        ]);
        config()->set('services.github_app.base_url', 'https://api.github.test');
        config()->set('services.github_app.api_version', '2026-03-10');
        config()->set('services.github_app.client_id', 'Iv1.test-client');
        config()->set('services.github_app.installation_id', 12345);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
    }

    public function test_github_app_token_uses_rs256_and_repository_scoped_issue_permissions(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        config()->set('services.github_app.private_key_path', null);
        config()->set('services.github_app.private_key_base64', base64_encode($privateKey));

        Http::fake([
            'https://api.github.test/app/installations/12345/access_tokens' => Http::response([
                'token' => 'installation-token',
                'expires_at' => now()->addHour()->toISOString(),
            ], 201),
        ]);

        $this->assertSame('installation-token', app(GitHubAppTokenProvider::class)->token());

        Http::assertSent(function (Request $request): bool {
            $jwt = str($request->header('Authorization')[0])->after('Bearer ')->toString();
            [$header, $payload] = array_map(
                fn (string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR),
                array_slice(explode('.', $jwt), 0, 2),
            );

            return $request->method() === 'POST'
                && $request->url() === 'https://api.github.test/app/installations/12345/access_tokens'
                && $request->header('X-GitHub-Api-Version')[0] === '2026-03-10'
                && $header['alg'] === 'RS256'
                && $payload['iss'] === 'Iv1.test-client'
                && $request['repositories'] === ['fishcounts']
                && $request['permissions'] === ['issues' => 'write'];
        });
    }

    public function test_it_creates_required_labels_uses_only_an_existing_source_label_and_posts_exact_issue_fields(): void
    {
        $this->cacheToken();
        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = [$request->method(), $request->url()];

            return match (true) {
                str_ends_with($request->url(), '/labels/parser-bug') => Http::response([], 404),
                str_ends_with($request->url(), '/labels/llm-detected') => Http::response(['name' => 'llm-detected']),
                str_ends_with($request->url(), '/labels/fishermans_landing') => Http::response(['name' => 'fishermans_landing']),
                str_ends_with($request->url(), '/labels') => Http::response(['name' => 'parser-bug'], 201),
                str_contains($request->url(), '/search/issues') => Http::response(['items' => []]),
                str_ends_with($request->url(), '/issues') => Http::response([
                    'number' => 81,
                    'html_url' => 'https://github.com/giantsoup/fishcounts/issues/81',
                    'state' => 'open',
                ], 201),
                default => Http::response([], 500),
            };
        });

        $issue = app(GitHubIssueTracker::class)->create($this->issue());

        $this->assertSame(81, $issue->number);
        $this->assertSame('open', $issue->state);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/repos/giantsoup/fishcounts/issues')) {
                return false;
            }

            return $request['title'] === '[Parser][fishermans_landing] Incorrect value extraction'
                && $request['body'] === '<!-- parser-bug-signature: '.str_repeat('a', 64)." -->\nBody"
                && $request['labels'] === ['parser-bug', 'llm-detected', 'fishermans_landing']
                && $request['assignees'] === ['giantsoup']
                && $request->header('Authorization') === ['Bearer cached-installation-token'];
        });
        $this->assertContains(['POST', 'https://api.github.test/repos/giantsoup/fishcounts/labels'], $seen);
    }

    public function test_label_creation_race_is_reconciled_after_a_422_response(): void
    {
        $this->cacheToken();
        config()->set('fish.github_issues.required_labels', [
            'parser-bug' => ['color' => 'd73a4a', 'description' => 'Parser defect.'],
        ]);
        $labelChecks = 0;
        Http::fake(function (Request $request) use (&$labelChecks) {
            if (str_ends_with($request->url(), '/labels/parser-bug')) {
                $labelChecks++;

                return $labelChecks === 1 ? Http::response([], 404) : Http::response(['name' => 'parser-bug']);
            }

            return match (true) {
                str_ends_with($request->url(), '/labels') => Http::response(['message' => 'Validation Failed'], 422),
                str_ends_with($request->url(), '/labels/fishermans_landing') => Http::response([], 404),
                str_contains($request->url(), '/search/issues') => Http::response(['items' => []]),
                str_ends_with($request->url(), '/issues') => Http::response([
                    'number' => 82,
                    'html_url' => 'https://github.com/giantsoup/fishcounts/issues/82',
                    'state' => 'open',
                ], 201),
                default => Http::response([], 500),
            };
        });

        $this->assertSame(82, app(GitHubIssueTracker::class)->create($this->issue())->number);
        $this->assertSame(2, $labelChecks);
    }

    public function test_secondary_rate_limit_is_retried_but_permission_failure_is_not(): void
    {
        $this->cacheToken();
        config()->set('fish.github_issues.required_labels', ['parser-bug' => ['color' => 'd73a4a', 'description' => 'Parser defect.']]);
        $requiredLabelChecks = 0;
        $sourceLabelChecks = 0;
        Http::fake(function (Request $request) use (&$requiredLabelChecks, &$sourceLabelChecks) {
            if (str_ends_with($request->url(), '/labels/parser-bug')) {
                $requiredLabelChecks++;

                return $requiredLabelChecks === 1
                    ? Http::response(['message' => 'You have exceeded a secondary rate limit.'], 403, ['Retry-After' => '0'])
                    : Http::response(['name' => 'parser-bug']);
            }

            $sourceLabelChecks++;

            return Http::response(['message' => 'Forbidden'], 403);
        });

        try {
            app(GitHubIssueTracker::class)->create($this->issue());
            $this->fail('The ordinary permission failure should not be retried.');
        } catch (RequestException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, $requiredLabelChecks);
        $this->assertSame(1, $sourceLabelChecks);
    }

    public function test_repository_cannot_be_redirected_by_configuration(): void
    {
        $this->cacheToken();
        config()->set('fish.github_issues.repository', 'attacker/repository');

        $this->expectException(RuntimeException::class);

        app(GitHubIssueTracker::class)->create($this->issue());
    }

    public function test_non_idempotent_issue_creation_is_not_retried_after_a_connection_failure(): void
    {
        $this->cacheToken();
        $issueCreationAttempts = 0;
        Http::fake(function (Request $request) use (&$issueCreationAttempts) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/issues')) {
                $issueCreationAttempts++;

                return Http::failedConnection('GitHub timed out.')($request);
            }

            return match (true) {
                str_contains($request->url(), '/labels/') => Http::response(['name' => 'existing-label']),
                str_contains($request->url(), '/search/issues') => Http::response(['items' => []]),
                default => Http::response([], 500),
            };
        });

        try {
            app(GitHubIssueTracker::class)->create($this->issue());
            $this->fail('The connection failure should be propagated to the queue job.');
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $issueCreationAttempts);
    }

    public function test_issue_response_must_point_to_the_fixed_repository(): void
    {
        $this->cacheToken();
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/labels/') => Http::response(['name' => 'existing-label']),
                str_contains($request->url(), '/search/issues') => Http::response(['items' => []]),
                str_ends_with($request->url(), '/issues') => Http::response([
                    'number' => 99,
                    'html_url' => 'javascript:alert(1)',
                    'state' => 'open',
                ], 201),
                default => Http::response([], 500),
            };
        });

        $this->expectException(RuntimeException::class);

        app(GitHubIssueTracker::class)->create($this->issue());
    }

    public function test_existing_remote_signature_is_recovered_without_creating_a_duplicate(): void
    {
        $this->cacheToken();
        $signature = str_repeat('a', 64);
        Http::fake(function (Request $request) use ($signature) {
            return match (true) {
                str_contains($request->url(), '/labels/') => Http::response(['name' => 'existing-label']),
                str_contains($request->url(), '/search/issues') => Http::response(['items' => [[
                    'number' => 77,
                    'html_url' => 'https://github.com/giantsoup/fishcounts/issues/77',
                    'state' => 'open',
                    'body' => "<!-- parser-bug-signature: {$signature} -->",
                ]]]),
                default => Http::response([], 500),
            };
        });

        $issue = app(GitHubIssueTracker::class)->create($this->issue());

        $this->assertSame(77, $issue->number);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/issues'));
    }

    public function test_issue_validation_failure_is_not_retried_by_the_non_idempotent_client(): void
    {
        $this->cacheToken();
        $issueCreationAttempts = 0;
        Http::fake(function (Request $request) use (&$issueCreationAttempts) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/issues')) {
                $issueCreationAttempts++;

                return Http::response(['message' => 'Validation Failed'], 422);
            }

            return match (true) {
                str_contains($request->url(), '/labels/') => Http::response(['name' => 'existing-label']),
                str_contains($request->url(), '/search/issues') => Http::response(['items' => []]),
                default => Http::response([], 500),
            };
        });

        try {
            app(GitHubIssueTracker::class)->create($this->issue());
            $this->fail('The validation failure should be propagated to the queue job.');
        } catch (RequestException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $issueCreationAttempts);
    }

    private function cacheToken(): void
    {
        $key = 'github-app-installation-token-'.hash('sha256', '12345|giantsoup/fishcounts');
        Cache::store('array')->put($key, 'cached-installation-token', now()->addHour());
    }

    private function issue(): ParserBugIssueData
    {
        return new ParserBugIssueData(
            title: '[Parser][fishermans_landing] Incorrect value extraction',
            body: '<!-- parser-bug-signature: '.str_repeat('a', 64)." -->\nBody",
            requiredLabels: array_keys(config('fish.github_issues.required_labels')),
            optionalLabels: ['fishermans_landing'],
            assignees: ['giantsoup'],
        );
    }
}
