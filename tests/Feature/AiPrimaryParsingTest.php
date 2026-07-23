<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParseRawPayloadOptions;
use App\Enums\AiBudgetReservationStatus;
use App\Enums\AiParserAttemptCostBasis;
use App\Enums\ParserEngine;
use App\Enums\ScrapeRunType;
use App\Exceptions\AiParserRateLimitExceededException;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Models\AiBudgetReservation;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use App\Services\AI\AiParserBudgetManager;
use App\Services\AI\AiReviewMetrics;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

class AiPrimaryParsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fish.ai_parsing.enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('fish.ai_parsing.budgets.estimated_attempt_cost_micros', 100_000);
        config()->set('fish.ai_parsing.budgets.daily_limit_micros', 5_000_000);
        config()->set('fish.ai_parsing.budgets.monthly_limit_micros', 50_000_000);
        RateLimiter::clear('ai-primary-parsing:openai');
        Http::preventStrayRequests();
    }

    public function test_valid_ai_output_is_authoritative_and_deterministic_output_is_only_a_comparison(): void
    {
        $payload = $this->payload();
        Http::fake(['*/responses' => Http::response($this->providerResponse(40), 200)]);
        Queue::fake();

        $result = app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'ai-success'),
        );

        $this->assertSame(1, $result->parsedReportCount);
        $report = TripReport::query()->with('speciesCounts')->sole();
        $this->assertSame('ai', $report->metadata['parser_engine']);
        $this->assertSame(40, $report->speciesCounts->sole()->count);
        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertSame('different', $execution->comparison_status);
        $this->assertNull($execution->fallback_category);
        $this->assertSame('resp_test', $execution->provider_response_id);
        $this->assertSame(200, $execution->provider_http_status);
        $this->assertSame('completed', $execution->provider_status);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiBudgetReservationStatus::Settled, $attempt->status);
        $this->assertSame(AiParserAttemptCostBasis::Metered, $attempt->cost_basis);
        $this->assertSame(400, $attempt->actual_micros);
        $this->assertSame('openai-list-price-v1', $attempt->cost_calculation_version);
        $this->assertNotNull($attempt->client_request_id);
        $this->assertSame($execution->id, $payload->refresh()->authoritative_parser_execution_id);
        $this->assertDatabaseCount('trip_reports', 1);
        $this->assertSame(0, app(AiReviewMetrics::class)->snapshot()['cost_micros']);
        Queue::assertNotPushed(DispatchParserDiagnosticReviewBatchesJob::class);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['model'] === 'gpt-5.6-luna'
            && $request['service_tier'] === 'default'
            && $request['store'] === false
            && $request['tools'] === []
            && $request['tool_choice'] === 'none'
            && $request['reasoning']['effort'] === 'medium'
            && $request['max_output_tokens'] === 16_000
            && $request['text']['format']['type'] === 'json_schema'
            && $request['text']['format']['strict'] === true
            && data_get($request, 'text.format.schema.properties.reports.items.properties.evidence_spans.type') === 'array'
            && data_get($request, 'text.format.schema.properties.reports.items.properties.species_counts.items.properties.evidence_spans.type') === 'array'
            && data_get($request, 'text.format.schema.properties.reports.items.properties.source_item_id.pattern') === '^block:\d{4}(?:#\d+)?$'
            && data_get($request, 'text.format.schema.properties.reports.items.properties.evidence_spans.items.minLength') === 1
            && str_contains($request['instructions'], 'Do not convert landing totals')
            && str_contains($request['instructions'], 'authoritative_target_date')
            && str_contains($request['instructions'], 'without brackets')
            && str_contains($request['instructions'], 'only the cited source_item_id block')
            && str_contains($request['instructions'], 'third tab-separated cell')
            && str_starts_with($request->header('X-Client-Request-Id')[0], 'fish-parser-'));
    }

    public function test_provider_authentication_failure_falls_back_to_deterministic_output(): void
    {
        $payload = $this->payload();
        Http::fake(['*/responses' => Http::response([
            'error' => [
                'message' => 'unauthorized',
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], 401, ['X-Request-Id' => 'req_auth_failure'])]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'ai-fallback'),
        );

        $report = TripReport::query()->with('speciesCounts')->sole();
        $this->assertSame('deterministic', $report->metadata['parser_engine']);
        $this->assertSame(40, $report->speciesCounts->sole()->count);
        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Ai, $execution->requested_engine);
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('authentication_failure', $execution->fallback_category);
        $this->assertSame('provider_request', $execution->fallback_stage);
        $this->assertSame('The AI provider returned HTTP 401.', $execution->fallback_message);
        $this->assertSame(401, $execution->provider_http_status);
        $this->assertSame('req_auth_failure', $execution->provider_request_id);
        $this->assertSame('invalid_api_key', $execution->provider_error_code);
        $this->assertSame('invalid_request_error', $execution->provider_error_type);
        $this->assertSame(0, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $this->assertSame('completed', $execution->status);
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiBudgetReservationStatus::Settled, $attempt->status);
        $this->assertSame(AiParserAttemptCostBasis::None, $attempt->cost_basis);
        $this->assertSame('req_auth_failure', $attempt->provider_request_id);
        $this->assertNotNull($attempt->response_received_at);
        $this->assertSame(64, strlen($attempt->provider_response_body_hash));
        $this->assertStringContainsString('unauthorized', $attempt->provider_output_excerpt);
        Http::assertSentCount(1);
    }

    public function test_fabricated_evidence_falls_back_without_writing_ai_reports(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = ['This evidence was fabricated.'];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'invalid-evidence'),
        );

        $this->assertSame(40, TripReport::query()->with('speciesCounts')->sole()->speciesCounts->sole()->count);
        $execution = ParserExecution::query()->sole();
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertSame('domain_validation', $execution->fallback_stage);
        $this->assertStringContainsString('fabricated evidence', $execution->fallback_message);
        $this->assertSame('resp_test', $execution->provider_response_id);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiParserAttemptCostBasis::Metered, $attempt->cost_basis);
        $this->assertSame('domain_validation', $attempt->failure_stage);
        $this->assertStringContainsString('fabricated evidence', $attempt->failure_message);
    }

    public function test_duplicate_evidence_spans_fall_back_before_persistence(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = [
            'Dolphin Full Day 20 anglers 40 Yellowtail',
            'Dolphin Full Day 20 anglers 40 Yellowtail',
        ];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'duplicate-evidence'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('duplicate evidence spans', $execution->fallback_message);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertDatabaseCount('trip_reports', 1);
    }

    public function test_transient_rate_limit_is_retried_once_with_separate_budget_reservations(): void
    {
        $payload = $this->payload();
        Http::fake(['*/responses' => Http::sequence()
            ->push(['error' => ['message' => 'rate limited']], 429)
            ->push($this->providerResponse(40), 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'retry-429'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertSame(2, $execution->attempts);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertDatabaseCount('ai_budget_reservations', 2);
        $attempts = AiBudgetReservation::query()->orderBy('attempt_number')->get();
        $this->assertSame(AiBudgetReservationStatus::Settled, $attempts[0]->status);
        $this->assertSame(AiParserAttemptCostBasis::None, $attempts[0]->cost_basis);
        $this->assertNotNull($attempts[0]->response_received_at);
        $this->assertStringContainsString('rate limited', $attempts[0]->provider_output_excerpt);
        $this->assertSame(AiBudgetReservationStatus::Settled, $attempts[1]->status);
        $this->assertSame(AiParserAttemptCostBasis::Metered, $attempts[1]->cost_basis);
        Http::assertSentCount(2);
    }

    public function test_http_error_usage_is_billed_before_retrying(): void
    {
        $payload = $this->payload();
        $rateLimitedResponse = $this->providerResponse(40);
        $rateLimitedResponse['status'] = 'failed';
        $rateLimitedResponse['output'] = [];
        $rateLimitedResponse['error'] = [
            'type' => 'rate_limit_error',
            'code' => 'rate_limit_exceeded',
        ];
        Http::fake(['*/responses' => Http::sequence()
            ->push($rateLimitedResponse, 429, ['X-Request-Id' => 'req_rate_limited_with_usage'])
            ->push($this->providerResponse(40), 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'retry-429-with-usage'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertSame(2, $execution->attempts);
        $this->assertSame(300, $execution->total_tokens);
        $this->assertSame(800, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);

        $attempts = AiBudgetReservation::query()->orderBy('attempt_number')->get();
        $this->assertSame(AiBudgetReservationStatus::Settled, $attempts[0]->status);
        $this->assertSame(AiParserAttemptCostBasis::Metered, $attempts[0]->cost_basis);
        $this->assertSame(400, $attempts[0]->actual_micros);
        $this->assertSame('req_rate_limited_with_usage', $attempts[0]->provider_request_id);
        $this->assertSame('rate_limit_error', $attempts[0]->provider_error_type);
        $this->assertSame('rate_limit_exceeded', $attempts[0]->provider_error_code);
        $this->assertSame(AiBudgetReservationStatus::Settled, $attempts[1]->status);
        $this->assertSame(800, $attempts[1]->budgetPeriod->spent_micros);
        $this->assertSame(800, $attempts[1]->dailyBudgetPeriod->spent_micros);
        Http::assertSentCount(2);
    }

    public function test_request_timeout_is_distinguished_from_other_connection_failures(): void
    {
        $payload = $this->payload();
        Http::fake([
            '*/responses' => Http::failedConnection('cURL error 28: Operation timed out after 120000 milliseconds'),
        ]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'request-timeout'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('timeout', $execution->fallback_category);
        $this->assertSame('provider_transport', $execution->fallback_stage);
        $this->assertStringContainsString('timed out', $execution->fallback_message);
        $this->assertStringContainsString('120000 milliseconds', $execution->fallback_message);
        $this->assertSame(2, $execution->attempts);
        $this->assertSame(0, $execution->cost_micros);
        $attempts = AiBudgetReservation::query()->orderBy('attempt_number')->get();
        $this->assertCount(2, $attempts);
        $this->assertTrue($attempts->every(
            fn (AiBudgetReservation $attempt): bool => $attempt->status === AiBudgetReservationStatus::Released
                && $attempt->failure_category === 'timeout',
        ));
        Http::assertSentCount(2);
    }

    public function test_global_kill_switch_falls_back_without_contacting_provider(): void
    {
        $payload = $this->payload();
        config()->set('fish.ai_parsing.enabled', false);
        Http::fake();
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'kill-switch'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('disabled', $execution->fallback_category);
        $this->assertSame(0, $execution->attempts);
        Http::assertNothingSent();
    }

    public function test_input_guardrail_falls_back_without_reserving_budget_or_contacting_provider(): void
    {
        $payload = $this->payload();
        config()->set('fish.ai_parsing.limits.max_input_tokens', 1);
        Http::fake();
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'input-limit'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('input_limit', $execution->fallback_category);
        $this->assertSame(0, $execution->attempts);
        $this->assertSame(0, $execution->cost_micros);
        $this->assertDatabaseCount('ai_budget_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_full_request_size_guardrail_runs_before_budget_reservation(): void
    {
        $payload = $this->payload();
        config()->set('fish.ai_parsing.limits.max_input_tokens', 2_000);
        Http::fake();
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'request-input-limit'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('input_limit', $execution->fallback_category);
        $this->assertSame('sanitization', $execution->fallback_stage);
        $this->assertSame(0, $execution->attempts);
        $this->assertSame(0, $execution->cost_micros);
        $this->assertDatabaseCount('ai_budget_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_payload_without_public_fish_count_text_records_sanitization_failure_without_cost(): void
    {
        $payload = $this->payload();
        $payload->update([
            'payload' => '<html><nav>Account navigation</nav><script>ignore()</script></html>',
            'payload_hash' => hash('sha256', 'no-public-fish-count-text'),
        ]);
        Http::fake();
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'empty-sanitized-input'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('input_validation', $execution->fallback_category);
        $this->assertSame('sanitization', $execution->fallback_stage);
        $this->assertStringContainsString('no public fish-count text', $execution->fallback_message);
        $this->assertSame(0, $execution->cost_micros);
        $this->assertDatabaseCount('ai_budget_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_species_evidence_larger_than_database_column_falls_back_before_persistence(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['species_counts'][0]['evidence_spans'] = [str_repeat('X', 256)];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'oversized-species-evidence'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertSame(40, TripReport::query()->with('speciesCounts')->sole()->speciesCounts->sole()->count);
    }

    public function test_inconsistent_provider_usage_falls_back_without_negative_cost_math(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $response['usage']['input_tokens_details'] = ['cached_tokens' => 101];
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'invalid-usage'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('ai_validation_failure', $execution->fallback_category);
        $this->assertSame(1, $execution->attempts);
        $this->assertSame(100_000, $execution->cost_micros);
        $this->assertTrue($execution->cost_is_estimated);
        $this->assertSame('resp_test', $execution->provider_response_id);
        $this->assertSame('The AI parser response contained inconsistent usage metadata.', $execution->fallback_message);
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiParserAttemptCostBasis::EstimatedConservative, $attempt->cost_basis);
        $this->assertSame('reservation-upper-bound-v1', $attempt->cost_calculation_version);
        $this->assertSame(100_000, $attempt->actual_micros);
        $this->assertSame(100_000, $attempt->dailyBudgetPeriod->spent_micros);
        $this->assertSame(100_000, $attempt->budgetPeriod->spent_micros);
    }

    public function test_malformed_structured_output_preserves_usage_exact_cost_and_provider_diagnostics(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $response['output'][0]['content'][0]['text'] = '{"reports": [malformed';
        Http::fake(['*/responses' => Http::response($response, 200, ['X-Request-Id' => 'req_malformed_output'])]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'malformed-output'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('ai_validation_failure', $execution->fallback_category);
        $this->assertSame('The AI parser returned malformed JSON.', $execution->fallback_message);
        $this->assertSame('req_malformed_output', $execution->provider_request_id);
        $this->assertSame('resp_test', $execution->provider_response_id);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $this->assertSame('{"reports": [malformed', $execution->provider_output_excerpt);
        $this->assertSame(64, strlen($execution->provider_response_body_hash));

        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiParserAttemptCostBasis::Metered, $attempt->cost_basis);
        $this->assertSame(400, $attempt->actual_micros);
        $this->assertSame(400, $attempt->dailyBudgetPeriod->spent_micros);
        $this->assertSame(400, $attempt->budgetPeriod->spent_micros);
        $this->assertSame($execution->provider_response_body_hash, $attempt->provider_response_body_hash);
    }

    public function test_malformed_response_metadata_still_preserves_reported_usage_and_exact_cost(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        unset($response['id']);
        Http::fake(['*/responses' => Http::response($response, 200, ['X-Request-Id' => 'req_missing_response_id'])]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'malformed-metadata'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('ai_validation_failure', $execution->fallback_category);
        $this->assertSame('The AI parser response omitted a valid response ID.', $execution->fallback_message);
        $this->assertNull($execution->provider_response_id);
        $this->assertSame('req_missing_response_id', $execution->provider_request_id);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $this->assertSame(AiParserAttemptCostBasis::Metered, AiBudgetReservation::query()->sole()->cost_basis);
    }

    public function test_non_json_provider_response_records_trace_data_and_conservative_cost(): void
    {
        $payload = $this->payload();
        Http::fake(['*/responses' => Http::response(
            '<html>malformed upstream response</html>',
            200,
            ['X-Request-Id' => 'req_non_json'],
        )]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'non-json-response'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('ai_validation_failure', $execution->fallback_category);
        $this->assertSame('The AI parser response was not a JSON object.', $execution->fallback_message);
        $this->assertSame(200, $execution->provider_http_status);
        $this->assertSame('req_non_json', $execution->provider_request_id);
        $this->assertSame(0, $execution->total_tokens);
        $this->assertSame(100_000, $execution->cost_micros);
        $this->assertTrue($execution->cost_is_estimated);
        $this->assertSame('<html>malformed upstream response</html>', $execution->provider_output_excerpt);
        $this->assertSame(64, strlen($execution->provider_response_body_hash));

        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiParserAttemptCostBasis::EstimatedConservative, $attempt->cost_basis);
        $this->assertSame(100_000, $attempt->actual_micros);
        $this->assertSame(100_000, $attempt->dailyBudgetPeriod->spent_micros);
        $this->assertSame(100_000, $attempt->budgetPeriod->spent_micros);
    }

    public function test_provider_refusal_falls_back_without_retrying(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $response['output'][0]['content'] = [['type' => 'refusal', 'refusal' => 'Unable to parse.']];
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'refusal'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame('refusal', $execution->fallback_category);
        $this->assertSame('provider_response', $execution->fallback_stage);
        $this->assertSame('The AI parser refused the request.', $execution->fallback_message);
        $this->assertSame('resp_test', $execution->provider_response_id);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiParserAttemptCostBasis::Metered, $attempt->cost_basis);
        $this->assertSame(400, $attempt->dailyBudgetPeriod->spent_micros);
        $this->assertSame(400, $attempt->budgetPeriod->spent_micros);
        Http::assertSentCount(1);
    }

    public function test_non_contiguous_species_evidence_spans_are_validated_independently(): void
    {
        $payload = $this->payload();
        $body = '<p>Dolphin Full Day 20 anglers 40 Yellowtail, 5 Calico Bass, 10 Yellowtail Released</p>';
        $payload->update([
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
        ]);
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = [
            'Dolphin Full Day 20 anglers 40 Yellowtail, 5 Calico Bass, 10 Yellowtail Released',
        ];
        $decoded['reports'][0]['raw_fish_count_text'] = '40 Yellowtail, 5 Calico Bass, 10 Yellowtail Released';
        $decoded['reports'][0]['species_counts'][0]['released_count'] = 10;
        $decoded['reports'][0]['species_counts'][0]['evidence_spans'] = [
            '40 Yellowtail',
            '10 Yellowtail Released',
        ];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'non-contiguous-evidence'),
        );

        $execution = ParserExecution::query()->sole();
        $speciesCount = TripReport::query()->with('speciesCounts')->sole()->speciesCounts->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertNull($execution->fallback_category);
        $this->assertSame(40, $speciesCount->count);
        $this->assertSame(10, $speciesCount->released_count);
        $this->assertSame('40 Yellowtail … 10 Yellowtail Released', $speciesCount->raw_count_text);
        Http::assertSentCount(1);
    }

    public function test_narrative_trip_phrase_can_map_to_an_active_canonical_trip_type(): void
    {
        $payload = $this->payload();
        $body = 'Dolphin PM trip 20 anglers 40 Yellowtail';
        $payload->update([
            'payload' => "<p>{$body}</p>",
            'payload_hash' => hash('sha256', $body),
        ]);
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = [$body];
        $decoded['reports'][0]['raw_trip_type'] = 'PM trip';
        $decoded['reports'][0]['canonical_trip_type_id'] = TripType::query()->where('name', '1/2 Day PM')->firstOrFail()->id;
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'narrative-trip-type'),
        );

        $execution = ParserExecution::query()->sole();
        $report = TripReport::query()->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertNull($execution->fallback_category);
        $this->assertSame('PM trip', $report->raw_trip_type);
    }

    public function test_authoritative_landing_source_can_omit_raw_landing_name_from_a_report_block(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['raw_landing_name'] = null;
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'source-landing-without-raw-name'),
        );

        $execution = ParserExecution::query()->sole();
        $report = TripReport::query()->with('landing')->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertNull($execution->fallback_category);
        $this->assertSame('Fisherman\'s Landing', $report->landing->name);
    }

    public function test_tabular_angler_cell_is_valid_evidence_without_the_anglers_label(): void
    {
        $payload = $this->payload();
        $body = '<table><tr><td>Dolphin</td><td>Full Day</td><td>20</td><td>40 Yellowtail</td></tr></table>';
        $payload->update([
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
        ]);
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = ["Dolphin\tFull Day\t20\t40 Yellowtail"];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'tabular-anglers'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertNull($execution->fallback_category);
        $this->assertSame(20, TripReport::query()->sole()->anglers);
    }

    public function test_limit_and_parenthetical_release_count_phrases_are_validated_safely(): void
    {
        $payload = $this->payload();
        $body = 'Dolphin Full Day 20 anglers LIMITS (26) of Dorado, 30 Calico Bass (100 released)';
        $payload->update([
            'payload' => "<p>{$body}</p>",
            'payload_hash' => hash('sha256', $body),
        ]);
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = [$body];
        $decoded['reports'][0]['raw_fish_count_text'] = 'LIMITS (26) of Dorado, 30 Calico Bass (100 released)';
        $decoded['reports'][0]['species_counts'] = [[
            'raw_species_name' => 'Dorado',
            'canonical_species_id' => Species::query()->where('name', 'Dorado')->firstOrFail()->id,
            'retained_count' => 26,
            'released_count' => 0,
            'evidence_spans' => ['LIMITS (26) of Dorado'],
        ], [
            'raw_species_name' => 'Calico Bass',
            'canonical_species_id' => Species::query()->where('name', 'Calico Bass')->firstOrFail()->id,
            'retained_count' => 30,
            'released_count' => 100,
            'evidence_spans' => ['30 Calico Bass', '100 released'],
        ]];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'narrative-count-formats'),
        );

        $execution = ParserExecution::query()->sole();
        $counts = TripReport::query()
            ->with('speciesCounts.species')
            ->sole()
            ->speciesCounts
            ->keyBy(fn (SpeciesCount $speciesCount): string => $speciesCount->species->name);
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertSame(26, $counts->get('Dorado')->count);
        $this->assertSame(30, $counts->get('Calico Bass')->count);
        $this->assertSame(100, $counts->get('Calico Bass')->released_count);
    }

    public function test_species_count_must_be_supported_by_its_exact_evidence_spans(): void
    {
        $payload = $this->payload();
        Http::fake(['*/responses' => Http::response($this->providerResponse(41), 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'unsupported-count'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('lacked retained-count evidence', $execution->fallback_message);
        $this->assertSame(40, TripReport::query()->with('speciesCounts')->sole()->speciesCounts->sole()->count);
        $this->assertSame(400, $execution->cost_micros);
        Http::assertSentCount(1);
    }

    public function test_species_count_cannot_be_borrowed_from_a_decimal_or_weight(): void
    {
        $this->assertUnsupportedCountEvidenceFallsBack(
            body: 'Dolphin Full Day 20 anglers 1.5 lb Bluefin Tuna, 1 Bluefin Tuna',
            speciesName: 'Bluefin Tuna',
            rawFishCountText: '1.5 lb Bluefin Tuna, 1 Bluefin Tuna',
            claimedCount: 5,
            speciesEvidence: '1.5 lb Bluefin Tuna',
            executionKey: 'decimal-count-evidence',
        );
    }

    public function test_species_count_cannot_be_borrowed_from_a_related_species_name(): void
    {
        $this->assertUnsupportedCountEvidenceFallsBack(
            body: 'Dolphin Full Day 20 anglers 115 Red Rockfish, 10 Rockfish',
            speciesName: 'Rockfish',
            rawFishCountText: '115 Red Rockfish, 10 Rockfish',
            claimedCount: 115,
            speciesEvidence: '115 Red Rockfish',
            executionKey: 'related-species-count-evidence',
        );
    }

    public function test_retained_count_cannot_truncate_a_release_marker_from_its_evidence(): void
    {
        $this->assertUnsupportedCountEvidenceFallsBack(
            body: 'Dolphin Full Day 20 anglers 100 Calico Bass Released, 5 Calico Bass',
            speciesName: 'Calico Bass',
            rawFishCountText: '100 Calico Bass',
            claimedCount: 100,
            speciesEvidence: '100 Calico Bass',
            executionKey: 'released-count-as-retained',
        );
    }

    public function test_report_boat_name_must_be_bound_to_its_evidence(): void
    {
        $payload = $this->payload();
        Boat::query()->where('name', 'Dolphin')->firstOrFail()->aliases()->create([
            'alias' => 'The Dolphin',
            'normalized_alias' => 'the dolphin',
        ]);
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['raw_boat_name'] = 'The Dolphin';
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'fabricated-report-fields'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('boat outside its evidence', $execution->fallback_message);
        $this->assertSame(40, TripReport::query()->with('speciesCounts')->sole()->speciesCounts->sole()->count);
    }

    public function test_boat_name_with_leading_article_and_trip_period_matches_canonical_boat(): void
    {
        $payload = $this->payload();
        $body = '<p>The Dolphin PM Full Day 20 anglers 40 Yellowtail</p>';
        $payload->update([
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
        ]);
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = ['The Dolphin PM Full Day 20 anglers 40 Yellowtail'];
        $decoded['reports'][0]['raw_boat_name'] = 'The Dolphin PM';
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'leading-boat-article'),
        );

        $execution = ParserExecution::query()->sole();
        $report = TripReport::query()->with('boat')->sole();
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertNull($execution->fallback_category);
        $this->assertSame('The Dolphin PM', $report->raw_boat_name);
        $this->assertSame('Dolphin', $report->boat->name);
    }

    public function test_report_angler_count_must_be_bound_to_its_evidence(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['anglers'] = 21;
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'fabricated-anglers'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('angler count outside its evidence', $execution->fallback_message);
    }

    public function test_report_raw_fish_count_text_must_be_bound_to_its_evidence(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['raw_fish_count_text'] = '41 Yellowtail';
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'fabricated-raw-counts'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('fabricated fish-count text', $execution->fallback_message);
    }

    public function test_raw_entity_name_must_match_the_selected_canonical_id(): void
    {
        $payload = $this->payload();
        $otherBoat = Boat::query()->create([
            'landing_id' => Landing::query()->where('slug', 'fishermans-landing')->firstOrFail()->id,
            'name' => 'Other Boat',
            'slug' => 'other-boat',
            'is_active' => true,
        ]);
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['canonical_boat_id'] = $otherBoat->id;
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'canonical-name-mismatch'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('wrong canonical ID', $execution->fallback_message);
    }

    public function test_source_identity_is_stable_when_valid_evidence_span_selection_changes(): void
    {
        $payload = $this->payload();
        $body = '<p>Dolphin Full Day 20 anglers 40 Yellowtail. Weather was calm.</p>';
        $payload->update([
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
        ]);
        $firstResponse = $this->providerResponse(40);
        $secondResponse = $this->providerResponse(40);
        $decoded = json_decode($secondResponse['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = ['Dolphin Full Day 20 anglers 40 Yellowtail. Weather was calm.'];
        $secondResponse['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::sequence()
            ->push($firstResponse, 200)
            ->push($secondResponse, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'stable-identity-first'),
        );
        $firstIdentifier = TripReport::query()->sole()->metadata['source_trip_identifier'];

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'stable-identity-second'),
        );
        $secondIdentifier = TripReport::query()->sole()->metadata['source_trip_identifier'];

        $this->assertSame($firstIdentifier, $secondIdentifier);
        $this->assertDatabaseCount('trip_reports', 1);
        $this->assertSame(2, ParserExecution::query()->where('selected_engine', ParserEngine::Ai)->count());
    }

    public function test_aggregate_only_source_can_complete_with_an_authoritative_empty_ai_result(): void
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'sandiego_fish_reports')->firstOrFail();
        $source->update(['parser_engine' => ParserEngine::Ai]);
        $fishermansLanding = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        Boat::query()->firstOrCreate(
            ['landing_id' => $fishermansLanding->id, 'slug' => 'dolphin'],
            ['name' => 'Dolphin'],
        );
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-01',
        ]);
        $body = <<<'HTML'
            <table>
                <tr><th>Landing</th><th>Boats</th><th>Anglers</th><th>Dock Totals</th></tr>
                <tr><td>Oceanside Sea Center</td><td>4 Boats / 4 Trips</td><td>64 Anglers</td><td>105 Bonito, 47 Calico Bass</td></tr>
            </table>
            HTML;
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-01',
            'url' => 'https://www.sandiegofishreports.com/dock_totals/index.php',
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => now(),
        ]);
        $response = $this->providerResponse(40);
        $response['output'][0]['content'][0]['text'] = json_encode(['reports' => []], JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        $result = app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'aggregate-empty'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(0, $result->parsedReportCount);
        $this->assertSame(ParserEngine::Ai, $execution->selected_engine);
        $this->assertSame('match', $execution->comparison_status);
        $this->assertNull($execution->fallback_category);
        $this->assertDatabaseCount('trip_reports', 0);
        Http::assertSentCount(1);
    }

    public function test_empty_ai_result_falls_back_when_deterministic_parser_found_reports(): void
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'sandiego_fish_reports')->firstOrFail();
        $source->update(['parser_engine' => ParserEngine::Ai]);
        $fishermansLanding = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        Boat::query()->firstOrCreate(
            ['landing_id' => $fishermansLanding->id, 'slug' => 'dolphin'],
            ['name' => 'Dolphin'],
        );
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-01',
        ]);
        $body = <<<'HTML'
            <table>
                <tr><th>Boat</th><th>Trip</th><th>Anglers</th><th>Fish Count</th></tr>
                <tr><td>Dolphin</td><td>Full Day</td><td>20</td><td>40 Yellowtail</td></tr>
            </table>
            HTML;
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-01',
            'url' => 'https://www.sandiegofishreports.com/dock_totals/index.php',
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => now(),
        ]);
        $response = $this->providerResponse(40);
        $response['output'][0]['content'][0]['text'] = json_encode(['reports' => []], JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        $result = app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'empty-ai-nonempty-deterministic'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(1, $result->parsedReportCount);
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('deterministic parser found reports', $execution->fallback_message);
        $this->assertDatabaseCount('trip_reports', 1);
        $this->assertSame(400, $execution->cost_micros);
    }

    public function test_incomplete_provider_response_preserves_usage_reason_and_exact_cost(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $response['status'] = 'incomplete';
        $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
        $response['output'] = [];
        Http::fake(['*/responses' => Http::response($response, 200, ['X-Request-Id' => 'req_incomplete'])]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'incomplete'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('incomplete_output', $execution->fallback_category);
        $this->assertSame('provider_response', $execution->fallback_stage);
        $this->assertSame('The AI parser response was incomplete.', $execution->fallback_message);
        $this->assertSame('incomplete', $execution->provider_status);
        $this->assertSame('max_output_tokens', $execution->provider_incomplete_reason);
        $this->assertSame('req_incomplete', $execution->provider_request_id);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(AiParserAttemptCostBasis::Metered, $attempt->cost_basis);
        $this->assertSame('max_output_tokens', $attempt->provider_incomplete_reason);
    }

    public function test_persisted_failure_messages_redact_secret_shaped_values(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $response['model'] = 'sk-sensitive-provider-value';
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'redacted-failure'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertStringContainsString('[redacted]', $execution->fallback_message);
        $this->assertStringNotContainsString('sk-sensitive-provider-value', $execution->fallback_message);
        $this->assertTrue($execution->cost_is_estimated);
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertStringContainsString('[redacted]', $attempt->failure_message);
        $this->assertStringNotContainsString('sk-sensitive-provider-value', $attempt->failure_message);
    }

    public function test_provider_server_errors_are_attempted_at_most_twice(): void
    {
        $payload = $this->payload();
        Http::fake(['*/responses' => Http::sequence()
            ->push(['error' => ['message' => 'unavailable']], 503)
            ->push(['error' => ['message' => 'still unavailable']], 503)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'retry-503'),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('provider_failure', $execution->fallback_category);
        $this->assertSame(2, $execution->attempts);
        $this->assertSame(0, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $this->assertTrue(AiBudgetReservation::query()->get()->every(
            fn (AiBudgetReservation $attempt): bool => $attempt->status === AiBudgetReservationStatus::Settled
                && $attempt->cost_basis === AiParserAttemptCostBasis::None
                && $attempt->response_received_at !== null
                && $attempt->provider_response_body_hash !== null
                && str_contains((string) $attempt->provider_output_excerpt, 'unavailable'),
        ));
        Http::assertSentCount(2);
    }

    public function test_metered_cost_can_exceed_the_reservation_without_being_clamped(): void
    {
        $payload = $this->payload();
        config()->set('fish.ai_parsing.budgets.estimated_attempt_cost_micros', 100);
        Http::fake(['*/responses' => Http::response($this->providerResponse(40), 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'actual-over-reservation'),
        );

        $execution = ParserExecution::query()->sole();
        $attempt = AiBudgetReservation::query()->sole();
        $this->assertSame(100, $attempt->reserved_micros);
        $this->assertSame(400, $attempt->actual_micros);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertFalse($execution->cost_is_estimated);
        $this->assertSame(400, $attempt->dailyBudgetPeriod->spent_micros);
        $this->assertSame(400, $attempt->budgetPeriod->spent_micros);
    }

    public function test_provider_response_recorded_before_settlement_is_reconciled_without_duplicate_request(): void
    {
        $payload = $this->payload();
        $executionKey = 'reconcile-response';
        $execution = ParserExecution::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'idempotency_key' => $this->idempotencyKey($payload, $executionKey),
            'requested_engine' => ParserEngine::Ai,
            'status' => 'running',
            'payload_hash' => $payload->payload_hash,
            'started_at' => now(),
        ]);
        $attempt = app(AiParserBudgetManager::class)->reserve($execution, 1);
        $attempt->update([
            'client_request_id' => 'fish-parser-recovery-test',
            'provider_request_id' => 'req_recovery',
            'provider_response_id' => 'resp_recovery',
            'provider_http_status' => 200,
            'provider_status' => 'completed',
            'model' => 'gpt-5.6-luna',
            'service_tier' => 'default',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'reasoning_tokens' => 10,
            'total_tokens' => 150,
            'actual_micros' => 400,
            'cost_basis' => AiParserAttemptCostBasis::Metered,
            'cost_calculation_version' => 'openai-list-price-v1',
            'response_received_at' => now(),
        ]);
        Http::fake();
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: $executionKey),
        );

        $execution->refresh();
        $attempt->refresh();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('ai_validation_failure', $execution->fallback_category);
        $this->assertSame('resp_recovery', $execution->provider_response_id);
        $this->assertSame(150, $execution->total_tokens);
        $this->assertSame(400, $execution->cost_micros);
        $this->assertSame(AiBudgetReservationStatus::Settled, $attempt->status);
        $this->assertSame(400, $attempt->actual_micros);
        Http::assertNothingSent();
    }

    public function test_admin_can_inspect_persisted_fallback_and_attempt_details(): void
    {
        $payload = $this->payload();
        $response = $this->providerResponse(41);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = ['Fabricated provider evidence'];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200, ['X-Request-Id' => 'req_admin_detail'])]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'admin-detail'),
        );

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->assertSee('Completed via deterministic fallback')
            ->assertSee('domain validation')
            ->assertSee('fabricated evidence')
            ->assertSee('Provider attempt audit')
            ->assertSee('req_admin_detail')
            ->assertSee('resp_test')
            ->assertSee('Response body SHA-256')
            ->assertSee('Provider output excerpt')
            ->assertSee('metered');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Schema/domain failures')
            ->assertSee('Estimated-cost executions');
    }

    public function test_stale_payload_is_rejected_before_creating_an_execution_or_contacting_provider(): void
    {
        $payload = $this->payload();
        RawScrapePayload::query()->create([
            'scrape_run_id' => $payload->scrape_run_id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'url' => $payload->url,
            'payload' => '<p>Dolphin Full Day 20 anglers 41 Yellowtail</p>',
            'payload_hash' => hash('sha256', 'newer-ai-fixture'),
            'fetched_at' => $payload->fetched_at,
        ]);
        Http::fake();
        Queue::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A newer raw payload is authoritative');

        try {
            app(ParseRawPayloadAction::class)->handleWithOptions(
                $payload->id,
                new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'stale'),
            );
        } finally {
            $this->assertDatabaseCount('parser_executions', 0);
            Http::assertNothingSent();
        }
    }

    public function test_failed_non_retryable_execution_is_not_sent_to_provider_again_on_job_retry(): void
    {
        $payload = $this->payload();
        $executionKey = 'failed-non-retryable';
        ParserExecution::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'idempotency_key' => $this->idempotencyKey($payload, $executionKey),
            'requested_engine' => ParserEngine::Ai,
            'status' => 'failed',
            'payload_hash' => $payload->payload_hash,
            'attempts' => 1,
            'fallback_category' => 'authentication_failure',
            'failure_category' => 'authentication_failure',
            'started_at' => now(),
        ]);
        Http::fake();
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: $executionKey),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame('completed', $execution->status);
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('authentication_failure', $execution->fallback_category);
        Http::assertNothingSent();
    }

    public function test_source_engine_is_preserved_by_the_legacy_action_entry_point(): void
    {
        $payload = $this->payload();
        Http::fake(['*/responses' => Http::response($this->providerResponse(40), 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handle($payload->id);

        $this->assertSame(ParserEngine::Ai, ParserExecution::query()->sole()->selected_engine);
        $this->assertSame(40, TripReport::query()->with('speciesCounts')->sole()->speciesCounts->sole()->count);
    }

    public function test_local_request_capacity_delays_ai_instead_of_completing_a_deterministic_fallback(): void
    {
        $payload = $this->payload();
        $limiter = new CacheRateLimiter(Cache::store('database'));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $limiter->hit('ai-primary-parsing:openai', 60);
        }
        Http::fake();
        Queue::fake();

        $this->expectException(AiParserRateLimitExceededException::class);

        try {
            app(ParseRawPayloadAction::class)->handleWithOptions(
                $payload->id,
                new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: 'local-capacity'),
            );
        } finally {
            $execution = ParserExecution::query()->sole();
            $this->assertSame('running', $execution->status);
            $this->assertNull($execution->selected_engine);
            $this->assertDatabaseCount('trip_reports', 0);
            Http::assertNothingSent();
        }
    }

    public function test_pruning_removes_only_expired_full_snapshots_and_keeps_compact_audit_metadata(): void
    {
        $payload = $this->payload();
        $base = [
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'requested_engine' => ParserEngine::Ai,
            'selected_engine' => ParserEngine::Ai,
            'status' => 'completed',
            'payload_hash' => $payload->payload_hash,
            'model' => 'gpt-5.6-luna',
            'cost_micros' => 1234,
            'started_at' => now()->subMonths(4),
            'completed_at' => now()->subMonths(4),
            'deterministic_snapshot' => ['parser_version' => 'deterministic-v1'],
            'ai_snapshot' => ['parser_version' => 'ai-v1'],
            'comparison' => ['status' => 'match'],
        ];
        $expired = ParserExecution::query()->create($base + [
            'idempotency_key' => hash('sha256', 'expired-execution'),
            'provider_output_excerpt' => 'expired output excerpt',
            'fallback_message' => 'expired fallback details',
            'created_at' => now()->subMonths(4),
            'updated_at' => now()->subMonths(4),
        ]);
        $current = ParserExecution::query()->create($base + [
            'idempotency_key' => hash('sha256', 'current-execution'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $expiredAttempt = app(AiParserBudgetManager::class)->reserve($expired, 1);
        $expiredAttempt->update([
            'provider_output_excerpt' => 'expired attempt output',
            'failure_message' => 'expired attempt failure',
        ]);

        $this->artisan('ai-parsing:prune')
            ->expectsOutput('Pruned full snapshots from 1 parser execution(s).')
            ->assertSuccessful();

        $expired->refresh();
        $current->refresh();
        $this->assertNull($expired->deterministic_snapshot);
        $this->assertNull($expired->ai_snapshot);
        $this->assertNull($expired->comparison);
        $this->assertNull($expired->provider_output_excerpt);
        $this->assertNull($expired->fallback_message);
        $this->assertNull($expiredAttempt->refresh()->provider_output_excerpt);
        $this->assertNull($expiredAttempt->failure_message);
        $this->assertSame('gpt-5.6-luna', $expired->model);
        $this->assertSame(1234, $expired->cost_micros);
        $this->assertNotNull($current->deterministic_snapshot);
        $this->assertNotNull($current->ai_snapshot);
        $this->assertNotNull($current->comparison);
    }

    private function assertUnsupportedCountEvidenceFallsBack(
        string $body,
        string $speciesName,
        string $rawFishCountText,
        int $claimedCount,
        string $speciesEvidence,
        string $executionKey,
    ): void {
        $payload = $this->payload();
        $payload->update([
            'payload' => "<p>{$body}</p>",
            'payload_hash' => hash('sha256', $body),
        ]);
        $species = Species::query()->where('name', $speciesName)->firstOrFail();
        $response = $this->providerResponse(40);
        $decoded = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $decoded['reports'][0]['evidence_spans'] = [$body];
        $decoded['reports'][0]['raw_fish_count_text'] = $rawFishCountText;
        $decoded['reports'][0]['species_counts'][0] = [
            'raw_species_name' => $speciesName,
            'canonical_species_id' => $species->id,
            'retained_count' => $claimedCount,
            'released_count' => 0,
            'evidence_spans' => [$speciesEvidence],
        ];
        $response['output'][0]['content'][0]['text'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        Http::fake(['*/responses' => Http::response($response, 200)]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handleWithOptions(
            $payload->id,
            new ParseRawPayloadOptions(parserEngine: ParserEngine::Ai, executionKey: $executionKey),
        );

        $execution = ParserExecution::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $execution->selected_engine);
        $this->assertSame('domain_validation', $execution->fallback_category);
        $this->assertStringContainsString('lacked retained-count evidence', $execution->fallback_message);
        $this->assertSame(400, $execution->cost_micros);
    }

    private function payload(): RawScrapePayload
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $source->update(['parser_engine' => ParserEngine::Ai]);
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        Boat::query()->firstOrCreate(
            ['landing_id' => $landing->id, 'slug' => 'dolphin'],
            ['name' => 'Dolphin'],
        );
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-01',
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-01',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php?date=2026-07-01',
            'payload' => '<p>Dolphin Full Day 20 anglers 40 Yellowtail</p>',
            'payload_hash' => hash('sha256', 'ai-fixture'),
            'fetched_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function providerResponse(int $yellowtailCount): array
    {
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        $boat = Boat::query()->where('name', 'Dolphin')->firstOrFail();
        $tripType = TripType::query()->where('name', 'Full Day')->firstOrFail();
        $species = Species::query()->where('name', 'Yellowtail')->firstOrFail();
        $text = json_encode([
            'reports' => [[
                'source_item_id' => 'block:0001',
                'evidence_spans' => ['Dolphin Full Day 20 anglers 40 Yellowtail'],
                'raw_boat_name' => 'Dolphin',
                'canonical_boat_id' => $boat->id,
                'raw_landing_name' => $landing->name,
                'canonical_landing_id' => $landing->id,
                'raw_trip_type' => 'Full Day',
                'canonical_trip_type_id' => $tripType->id,
                'anglers' => 20,
                'raw_fish_count_text' => "{$yellowtailCount} Yellowtail",
                'species_counts' => [[
                    'raw_species_name' => 'Yellowtail',
                    'canonical_species_id' => $species->id,
                    'retained_count' => $yellowtailCount,
                    'released_count' => 0,
                    'evidence_spans' => ['40 Yellowtail'],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        return [
            'id' => 'resp_test',
            'model' => 'gpt-5.6-luna',
            'service_tier' => 'default',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage' => [
                'input_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens' => 50,
                'output_tokens_details' => ['reasoning_tokens' => 10],
                'total_tokens' => 150,
            ],
        ];
    }

    private function idempotencyKey(RawScrapePayload $payload, string $executionKey): string
    {
        return hash('sha256', implode('|', [
            $executionKey,
            $payload->id,
            $payload->payload_hash,
            ParserEngine::Ai->value,
            config('fish.ai_parsing.model'),
            config('fish.ai_parsing.service_tier'),
            config('fish.ai_parsing.reasoning_effort'),
            config('fish.ai_parsing.prompt_version'),
            config('fish.ai_parsing.schema_version'),
            config('fish.ai_parsing.sanitizer_version'),
            config('fish.ai_parsing.catalog_version'),
        ]));
    }
}
