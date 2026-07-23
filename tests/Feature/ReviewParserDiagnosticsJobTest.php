<?php

namespace Tests\Feature;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\DTOs\ParserDiagnosticReviewProviderResponseData;
use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ScrapeRunType;
use App\Exceptions\OpenAiIncompleteResponseException;
use App\Exceptions\OpenAiResponseValidationException;
use App\Jobs\CreateParserBugIssueJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
        config()->set('fish.ai_review.budgets.estimated_request_cost_micros', 1_000_000);
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 10_000_000);
    }

    public function test_it_is_unique_rate_limited_and_uses_a_safe_queue_timeout(): void
    {
        $job = new ReviewParserDiagnosticsJob(123);

        $this->assertSame('123', $job->uniqueId());
        $this->assertSame('database', $job->connection);
        $this->assertSame('ai-parsing', $job->queue);
        $this->assertSame(0, $job->tries);
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[1]);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertGreaterThan($job->timeout, $job->uniqueFor());
        $this->assertGreaterThan(now(), $job->retryUntil());

        $manualJob = new ReviewParserDiagnosticsJob(123, 456);
        $this->assertSame('123:review-run:456', $manualJob->uniqueId());

        $fingerprints = [hash('sha256', 'first'), hash('sha256', 'second')];
        $automaticBatch = new ReviewParserDiagnosticsJob(123, diagnosticFingerprints: $fingerprints);
        $firstHistoricalBatch = new ReviewParserDiagnosticsJob(
            123,
            diagnosticFingerprints: $fingerprints,
            uniqueContext: 'historical-item:1',
        );
        $secondHistoricalBatch = new ReviewParserDiagnosticsJob(
            123,
            diagnosticFingerprints: $fingerprints,
            uniqueContext: 'historical-item:2',
        );
        $this->assertNotSame($automaticBatch->uniqueId(), $firstHistoricalBatch->uniqueId());
        $this->assertNotSame($firstHistoricalBatch->uniqueId(), $secondHistoricalBatch->uniqueId());
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
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Queued,
        ]);
        $unrelatedRun = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Queued,
        ]);
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
                    cacheWriteTokens: 20,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id, $run->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Succeeded, $review->status);
        $this->assertSame('uncertain', $review->classification->value);
        $this->assertSame('resp_test', $review->response_id);
        $this->assertSame(100, $review->input_tokens);
        $this->assertSame(20, $review->cache_write_tokens);
        $this->assertSame('default', $review->service_tier);
        $this->assertSame(336, $review->estimated_cost_micros);
        $this->assertSame('openai-list-price-v1', $review->cost_calculation_version);
        $this->assertSame([
            'model' => 'gpt-5.6-luna',
            'service_tier' => 'default',
            'input_cost_per_million_micros' => 1_000_000,
            'cached_input_cost_per_million_micros' => 100_000,
            'cache_write_cost_per_million_micros' => 1_250_000,
            'output_cost_per_million_micros' => 6_000_000,
        ], $review->pricing_snapshot);
        $this->assertDatabaseHas('ai_budget_reservations', [
            'reserved_micros' => 1_000_000,
            'actual_micros' => 336,
        ]);
        $this->assertModelExists($parserError);
        $this->assertNull($parserError->refresh()->resolved_at);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Completed, $run->refresh()->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Queued, $unrelatedRun->refresh()->status);
    }

    public function test_stale_model_instances_cannot_regress_a_terminal_run(): void
    {
        [$payload] = $this->payloadAndError();
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Preparing,
        ]);
        $stalePreparingRun = $run->fresh();
        $staleRunningRun = $run->fresh();

        $run->markRunning();
        $run->markCompleted();
        $stalePreparingRun->markQueued();
        $staleRunningRun->markFailed('A late failure must not overwrite completion.');

        $run->refresh();
        $this->assertSame(ParserDiagnosticReviewRunStatus::Completed, $run->status);
        $this->assertNull($run->failure_message);
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
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Queued,
        ]);
        config()->set('fish.ai_review.dispatch_enabled', false);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new LogicException('The provider must not be called.');
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id, $run->id), 'handle']);

        $this->assertDatabaseEmpty('parser_diagnostic_reviews');
        $this->assertDatabaseEmpty('ai_budget_reservations');
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $run->refresh()->status);
        $this->assertSame('AI review dispatch is no longer available.', $run->failure_message);
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
        $this->assertSame(55, $review->estimated_cost_micros);
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
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
        ]);
        $review = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
        ]);
        $review->transitionTo(ParserDiagnosticReviewStatus::Running);

        (new ReviewParserDiagnosticsJob($payload->id, $run->id))->failed(new RuntimeException('Bearer secret-token '.str_repeat('x', 100)));

        $review->refresh();
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertStringNotContainsString('secret-token', $review->failure_message);
        $this->assertLessThanOrEqual(40, strlen($review->failure_message));
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $run->refresh()->status);
        $this->assertStringNotContainsString('secret-token', $run->failure_message);
        $this->assertLessThanOrEqual(40, strlen($run->failure_message));
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

    public function test_an_invalid_result_does_not_prevent_later_batch_results_from_succeeding(): void
    {
        [$payload, $firstError] = $this->payloadAndError();
        $secondError = $firstError->replicate()->forceFill([
            'raw_value' => 'Sun Fish',
            'diagnostic_fingerprint' => hash('sha256', 'second-diagnostic'),
        ]);
        $secondError->save();
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($firstError, $secondError) implements ParserDiagnosticReviewer
        {
            public function __construct(
                private readonly ParserError $firstError,
                private readonly ParserError $secondError,
            ) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_partial_validation',
                    model: 'gpt-5.6-luna',
                    results: [
                        $this->firstError->diagnostic_fingerprint => [
                            'classification' => 'legitimate_alias',
                            'confidence' => 0.99,
                            'rationale' => 'The first result references an invalid candidate.',
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
                        ],
                        $this->secondError->diagnostic_fingerprint => [
                            'classification' => 'uncertain',
                            'confidence' => 0.4,
                            'rationale' => 'The second result needs human review.',
                            'corrections' => [],
                        ],
                    ],
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

        $firstReview = ParserDiagnosticReview::query()->whereBelongsTo($firstError, 'parserError')->sole();
        $secondReview = ParserDiagnosticReview::query()->whereBelongsTo($secondError, 'parserError')->sole();

        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $firstReview->status);
        $this->assertSame('schema_validation', $firstReview->failure_category);
        $this->assertSame(ParserDiagnosticReviewStatus::Succeeded, $secondReview->status);
        $this->assertSame('resp_partial_validation', $secondReview->response_id);
    }

    public function test_an_incomplete_response_records_provider_metadata_without_a_queue_retry(): void
    {
        [$payload] = $this->payloadAndError();
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
        ]);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new OpenAiIncompleteResponseException(
                    responseId: 'resp_incomplete',
                    model: 'gpt-5.6-luna-2026-07-01',
                    reason: 'max_output_tokens',
                    inputTokens: 120,
                    cachedInputTokens: 20,
                    outputTokens: 16000,
                    reasoningTokens: 15000,
                    totalTokens: 16120,
                    cacheWriteTokens: 5,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id, $run->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertSame('output_token_limit', $review->failure_category);
        $this->assertSame('resp_incomplete', $review->response_id);
        $this->assertSame('gpt-5.6-luna-2026-07-01', $review->model);
        $this->assertSame(120, $review->input_tokens);
        $this->assertSame(20, $review->cached_input_tokens);
        $this->assertSame(16000, $review->output_tokens);
        $this->assertSame(15000, $review->reasoning_tokens);
        $this->assertSame(16120, $review->total_tokens);
        $this->assertSame(96_104, $review->estimated_cost_micros);
        $this->assertSame(1, $review->attempts);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $run->refresh()->status);
        $this->assertDatabaseHas('ai_budget_reservations', [
            'status' => 'settled',
            'actual_micros' => 96_104,
        ]);
    }

    public function test_invalid_structured_output_settles_the_reported_provider_cost(): void
    {
        [$payload] = $this->payloadAndError();
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
        ]);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new OpenAiResponseValidationException(
                    message: 'The OpenAI response contained malformed JSON.',
                    responseId: 'resp_malformed',
                    model: 'gpt-5.6-luna',
                    inputTokens: 100,
                    cachedInputTokens: 10,
                    cacheWriteTokens: 20,
                    outputTokens: 40,
                    reasoningTokens: 20,
                    totalTokens: 140,
                    serviceTier: 'default',
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id, $run->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertSame('schema_validation', $review->failure_category);
        $this->assertSame('resp_malformed', $review->response_id);
        $this->assertSame(336, $review->estimated_cost_micros);
        $this->assertDatabaseHas('ai_budget_reservations', [
            'status' => 'settled',
            'actual_micros' => 336,
        ]);
    }

    public function test_missing_usage_consumes_the_conservative_reservation_without_recording_a_false_cost(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
        ]);
        $existingReview = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v4',
            'schema_version' => 'v2',
        ]);
        $existingReview->transitionTo(ParserDiagnosticReviewStatus::Running);
        $existingReview->forceFill([
            'response_id' => 'resp_old',
            'service_tier' => 'default',
            'input_tokens' => 50,
            'cached_input_tokens' => 10,
            'cache_write_tokens' => 5,
            'output_tokens' => 20,
            'reasoning_tokens' => 10,
            'total_tokens' => 70,
            'estimated_cost_micros' => 100,
            'cost_calculation_version' => 'openai-list-price-v1',
            'pricing_snapshot' => ['model' => 'gpt-5.6-luna'],
        ])->save();
        $existingReview->fail('Previous failure.', 'schema_validation');
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new OpenAiResponseValidationException(
                    message: 'The OpenAI response did not contain valid usage metadata.',
                    responseId: 'resp_missing_usage',
                    model: 'gpt-5.6-luna',
                    inputTokens: 0,
                    cachedInputTokens: 0,
                    cacheWriteTokens: 0,
                    outputTokens: 0,
                    reasoningTokens: 0,
                    totalTokens: 0,
                    serviceTier: 'default',
                    hasValidUsage: false,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id, $run->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertSame('schema_validation', $review->failure_category);
        $this->assertSame('resp_missing_usage', $review->response_id);
        $this->assertNull($review->input_tokens);
        $this->assertSame(0, $review->cached_input_tokens);
        $this->assertSame(0, $review->cache_write_tokens);
        $this->assertNull($review->total_tokens);
        $this->assertNull($review->estimated_cost_micros);
        $this->assertNull($review->cost_calculation_version);
        $this->assertNull($review->pricing_snapshot);
        $this->assertDatabaseHas('ai_budget_reservations', [
            'status' => 'settled',
            'actual_micros' => 1_000_000,
        ]);
    }

    public function test_unpriced_response_metadata_falls_back_without_stranding_the_reservation(): void
    {
        [$payload] = $this->payloadAndError();
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
        ]);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new OpenAiResponseValidationException(
                    message: 'The OpenAI response contained malformed JSON.',
                    responseId: 'resp_unpriced',
                    model: 'unpriced-model',
                    inputTokens: 100,
                    cachedInputTokens: 10,
                    cacheWriteTokens: 20,
                    outputTokens: 40,
                    reasoningTokens: 20,
                    totalTokens: 140,
                    serviceTier: 'default',
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id, $run->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertSame(140, $review->total_tokens);
        $this->assertNull($review->estimated_cost_micros);
        $this->assertDatabaseHas('ai_budget_reservations', [
            'status' => 'settled',
            'actual_micros' => 1_000_000,
        ]);
    }

    public function test_a_failed_final_batch_does_not_discard_a_successful_earlier_batch(): void
    {
        [$payload, $firstError] = $this->payloadAndError();
        $secondError = $firstError->replicate()->forceFill([
            'raw_value' => 'Sun Fish',
            'diagnostic_fingerprint' => hash('sha256', 'second-batch-diagnostic'),
        ]);
        $secondError->save();
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
        ]);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($firstError, $secondError) implements ParserDiagnosticReviewer
        {
            public function __construct(
                private readonly ParserError $firstError,
                private readonly ParserError $secondError,
            ) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                $fingerprint = $requests[0]->diagnosticFingerprint;
                if ($fingerprint === $this->secondError->diagnostic_fingerprint) {
                    throw new OpenAiIncompleteResponseException(
                        responseId: 'resp_second_incomplete',
                        model: 'gpt-5.6-luna',
                        reason: 'max_output_tokens',
                        inputTokens: 40,
                        cachedInputTokens: 0,
                        outputTokens: 16000,
                        reasoningTokens: 15000,
                        totalTokens: 16040,
                    );
                }

                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_first_success',
                    model: 'gpt-5.6-luna',
                    results: [$this->firstError->diagnostic_fingerprint => [
                        'classification' => 'uncertain',
                        'confidence' => 0.4,
                        'rationale' => 'The first batch succeeded.',
                        'corrections' => [],
                    ]],
                    refused: false,
                    refusal: null,
                    inputTokens: 30,
                    cachedInputTokens: 0,
                    outputTokens: 20,
                    reasoningTokens: 5,
                    totalTokens: 50,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob(
            $payload->id,
            $run->id,
            [$firstError->diagnostic_fingerprint],
            false,
        ), 'handle']);
        app()->call([new ReviewParserDiagnosticsJob(
            $payload->id,
            $run->id,
            [$secondError->diagnostic_fingerprint],
            true,
        ), 'handle']);

        $firstReview = ParserDiagnosticReview::query()->whereBelongsTo($firstError, 'parserError')->sole();
        $secondReview = ParserDiagnosticReview::query()->whereBelongsTo($secondError, 'parserError')->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Succeeded, $firstReview->status);
        $this->assertSame('resp_first_success', $firstReview->response_id);
        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $secondReview->status);
        $this->assertSame('output_token_limit', $secondReview->failure_category);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $run->refresh()->status);
        $this->assertDatabaseCount('ai_budget_reservations', 2);
    }

    public function test_a_retry_refreshes_the_provider_contract_metadata(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $review = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => hash('sha256', 'old-payload'),
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'provider' => 'old-provider',
            'model' => 'old-model',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
        ]);
        $review->transitionTo(ParserDiagnosticReviewStatus::Running);
        $review->fail('Old schema failure.', 'schema_validation');
        $review->prepareForRetry();

        config()->set('fish.ai_review.provider', 'openai');
        config()->set('fish.ai_review.model', 'gpt-current');
        config()->set('fish.ai_review.pricing.model', 'gpt-current');
        config()->set('fish.ai_review.prompt_version', 'v2');
        config()->set('fish.ai_review.schema_version', 'v2');
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($parserError->diagnostic_fingerprint) implements ParserDiagnosticReviewer
        {
            public function __construct(private readonly string $fingerprint) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_current_contract',
                    model: 'gpt-current',
                    results: [$this->fingerprint => [
                        'classification' => 'uncertain',
                        'confidence' => 0.4,
                        'rationale' => 'A human should inspect this result.',
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

        $review->refresh();
        $this->assertSame(ParserDiagnosticReviewStatus::Succeeded, $review->status);
        $this->assertSame($payload->payload_hash, $review->payload_hash);
        $this->assertSame('openai', $review->provider);
        $this->assertSame('gpt-current', $review->model);
        $this->assertSame('v2', $review->prompt_version);
        $this->assertSame('v2', $review->schema_version);
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
