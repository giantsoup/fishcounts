<?php

namespace Tests\Feature;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\DTOs\ParserDiagnosticReviewProviderResponseData;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ScrapeRunType;
use App\Jobs\CreateParserBugIssueJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ReviewParserDiagnosticsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('fish.ai_review.budgets.estimated_request_cost_micros', 1000);
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 10000);
    }

    public function test_it_is_unique_rate_limited_and_uses_a_safe_queue_timeout(): void
    {
        $job = new ReviewParserDiagnosticsJob(123);

        $this->assertSame('123', $job->uniqueId());
        $this->assertSame('database', $job->connection);
        $this->assertSame('ai-parsing', $job->queue);
        $this->assertSame(0, $job->tries);
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertGreaterThan(now(), $job->retryUntil());
    }

    public function test_duplicate_payload_jobs_are_suppressed_by_the_database_unique_lock(): void
    {
        ReviewParserDiagnosticsJob::dispatch(987654);
        ReviewParserDiagnosticsJob::dispatch(987654);

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_it_records_a_validated_shadow_result_and_usage_without_mutating_the_parser_error(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($parserError->diagnostic_fingerprint) implements ParserDiagnosticReviewer
        {
            public function __construct(private readonly string $fingerprint) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_test',
                    model: 'gpt-5.6-luna',
                    results: [$this->fingerprint => [
                        'classification' => 'uncertain',
                        'confidence' => 0.4,
                        'rationale' => 'A human should inspect this alias.',
                        'corrections' => [],
                    ]],
                    refused: false,
                    refusal: null,
                    inputTokens: 100,
                    cachedInputTokens: 10,
                    outputTokens: 40,
                    reasoningTokens: 20,
                    totalTokens: 140,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Succeeded, $review->status);
        $this->assertSame('uncertain', $review->classification->value);
        $this->assertSame('resp_test', $review->response_id);
        $this->assertSame(100, $review->input_tokens);
        $this->assertSame(1000, $review->estimated_cost_micros);
        $this->assertModelExists($parserError);
        $this->assertNull($parserError->refresh()->resolved_at);
    }

    public function test_validated_parser_bug_dispatches_only_the_separate_github_job(): void
    {
        Queue::fake();
        config()->set('fish.github_issues.enabled', true);
        config()->set('fish.github_issues.write_enabled', false);
        [$payload, $parserError] = $this->payloadAndError();
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($parserError->diagnostic_fingerprint) implements ParserDiagnosticReviewer
        {
            public function __construct(private readonly string $fingerprint) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_parser_bug',
                    model: 'gpt-5.6-luna',
                    results: [$this->fingerprint => [
                        'classification' => 'value_extraction_error',
                        'confidence' => 0.99,
                        'rationale' => 'The deterministic parser extracted the wrong value.',
                        'corrections' => [],
                    ]],
                    refused: false,
                    refusal: null,
                    inputTokens: 20,
                    cachedInputTokens: 0,
                    outputTokens: 10,
                    reasoningTokens: 0,
                    totalTokens: 30,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        Queue::assertPushed(CreateParserBugIssueJob::class, fn (CreateParserBugIssueJob $job): bool => $job->parserDiagnosticReviewId === $review->id);
        Queue::assertNotPushed(ReviewParserDiagnosticsJob::class);
    }

    public function test_disabled_queued_jobs_no_op_without_resolving_or_calling_the_provider(): void
    {
        [$payload] = $this->payloadAndError();
        config()->set('fish.ai_review.dispatch_enabled', false);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new LogicException('The provider must not be called.');
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $this->assertDatabaseEmpty('parser_diagnostic_reviews');
        $this->assertDatabaseEmpty('ai_budget_reservations');
    }

    public function test_budget_exhaustion_skips_the_review_without_calling_the_provider(): void
    {
        [$payload] = $this->payloadAndError();
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 500);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new LogicException('The provider must not be called without a budget reservation.');
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $this->assertSame(ParserDiagnosticReviewStatus::Skipped, ParserDiagnosticReview::query()->sole()->status);
        $this->assertDatabaseEmpty('ai_budget_reservations');
    }

    public function test_provider_refusal_is_audited_and_leaves_the_parser_error_actionable(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_refusal',
                    model: 'gpt-5.6-luna',
                    results: [],
                    refused: true,
                    refusal: 'Unable to review this source paragraph.',
                    inputTokens: 25,
                    cachedInputTokens: 0,
                    outputTokens: 5,
                    reasoningTokens: 0,
                    totalTokens: 30,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Refused, $review->status);
        $this->assertSame('resp_refusal', $review->response_id);
        $this->assertSame(1000, $review->estimated_cost_micros);
        $this->assertModelExists($parserError);
        $this->assertNull($parserError->refresh()->resolution_type);
    }

    public function test_a_result_is_marked_stale_when_its_fingerprint_disappears_during_review(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($parserError) implements ParserDiagnosticReviewer
        {
            public function __construct(private readonly ParserError $parserError) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                $fingerprint = $requests[0]->diagnosticFingerprint;
                $this->parserError->delete();

                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_stale',
                    model: 'gpt-5.6-luna',
                    results: [$fingerprint => [
                        'classification' => 'uncertain',
                        'confidence' => 0.4,
                        'rationale' => 'This result became stale.',
                        'corrections' => [],
                    ]],
                    refused: false,
                    refusal: null,
                    inputTokens: 10,
                    cachedInputTokens: 0,
                    outputTokens: 5,
                    reasoningTokens: 0,
                    totalTokens: 15,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Stale, $review->status);
        $this->assertNull($review->validated_result);
    }

    public function test_failed_callback_bounds_and_redacts_failure_metadata(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        config()->set('fish.ai_review.limits.max_failure_message_length', 40);
        $review = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
        ]);
        $review->transitionTo(ParserDiagnosticReviewStatus::Running);

        (new ReviewParserDiagnosticsJob($payload->id))->failed(new RuntimeException('Bearer secret-token '.str_repeat('x', 100)));

        $review->refresh();
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertStringNotContainsString('secret-token', $review->failure_message);
        $this->assertLessThanOrEqual(40, strlen($review->failure_message));
    }

    public function test_invalid_canonical_ids_fail_once_without_a_queue_level_retry(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($parserError->diagnostic_fingerprint) implements ParserDiagnosticReviewer
        {
            public function __construct(private readonly string $fingerprint) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_invalid',
                    model: 'gpt-5.6-luna',
                    results: [$this->fingerprint => [
                        'classification' => 'legitimate_alias',
                        'confidence' => 0.99,
                        'rationale' => 'Invalid candidate.',
                        'corrections' => [[
                            'operation' => 'map_alias',
                            'report_index' => 0,
                            'field' => 'species',
                            'canonical_type' => 'species',
                            'canonical_id' => 999999,
                            'value' => null,
                            'retained_count' => null,
                            'released_count' => null,
                        ]],
                    ]],
                    refused: false,
                    refusal: null,
                    inputTokens: 10,
                    cachedInputTokens: 0,
                    outputTokens: 10,
                    reasoningTokens: 0,
                    totalTokens: 20,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertSame(1, $review->attempts);
        $this->assertNull($review->validated_result);
    }

    public function test_multiple_diagnostics_for_one_payload_are_sent_in_one_batch(): void
    {
        [$payload, $firstError] = $this->payloadAndError();
        $secondError = $firstError->replicate()->forceFill([
            'raw_value' => 'Sun Fish',
            'diagnostic_fingerprint' => hash('sha256', 'second-diagnostic'),
        ]);
        $secondError->save();
        $batchSizes = [];
        $this->app->bind(ParserDiagnosticReviewer::class, function () use (&$batchSizes) {
            return new class($batchSizes) implements ParserDiagnosticReviewer
            {
                /** @param array<int, int> $batchSizes */
                public function __construct(private array &$batchSizes) {}

                public function review(array $requests): ParserDiagnosticReviewProviderResponseData
                {
                    $this->batchSizes[] = count($requests);
                    $results = [];

                    foreach ($requests as $request) {
                        $results[$request->diagnosticFingerprint] = [
                            'classification' => 'uncertain',
                            'confidence' => 0.3,
                            'rationale' => 'Needs human review.',
                            'corrections' => [],
                        ];
                    }

                    return new ParserDiagnosticReviewProviderResponseData(
                        responseId: 'resp_batch',
                        model: 'gpt-5.6-luna',
                        results: $results,
                        refused: false,
                        refusal: null,
                        inputTokens: 20,
                        cachedInputTokens: 0,
                        outputTokens: 10,
                        reasoningTokens: 0,
                        totalTokens: 30,
                    );
                }
            };
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $this->assertSame([2], $batchSizes);
        $this->assertSame(2, ParserDiagnosticReview::query()->where('status', ParserDiagnosticReviewStatus::Succeeded)->count());
    }

    /** @return array{RawScrapePayload, ParserError} */
    private function payloadAndError(): array
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-12',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-12',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<p>4 Moon Fish.</p>',
            'payload_hash' => hash('sha256', 'payload'),
            'fetched_at' => now(),
        ]);
        $parserError = ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => $payload->target_date,
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Moon Fish',
            'message' => 'Unknown species alias.',
            'context' => [
                'source' => $source->slug,
                'date' => '2026-07-12',
                'sanitized_paragraph' => '4 Moon Fish.',
                'extracted_fields' => ['species_counts' => [['species' => 'Moon Fish', 'retained' => 4, 'released' => 0]]],
                'evidence' => ['matched' => false],
            ],
            'report_fingerprint' => hash('sha256', 'report'),
            'diagnostic_fingerprint' => hash('sha256', 'diagnostic'),
        ]);

        return [$payload, $parserError];
    }
}
