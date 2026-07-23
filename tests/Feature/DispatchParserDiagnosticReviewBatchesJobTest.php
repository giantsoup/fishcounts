<?php

namespace Tests\Feature;

use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ParserEngine;
use App\Enums\ScrapeRunType;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DispatchParserDiagnosticReviewBatchesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('fish.ai_review.limits.max_diagnostics_per_request', 4);
        config()->set('services.openai.api_key', 'test-key');
    }

    public function test_it_dispatches_exact_bounded_batches_and_only_the_last_batch_finalizes_the_run(): void
    {
        Bus::fake();
        [$payload, $fingerprints] = $this->payloadWithErrors(9);
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Queued,
        ]);

        app()->call([new DispatchParserDiagnosticReviewBatchesJob($payload->id, $run->id), 'handle']);

        Bus::assertChained([
            new ReviewParserDiagnosticsJob($payload->id, $run->id, array_slice($fingerprints, 0, 4), false),
            new ReviewParserDiagnosticsJob($payload->id, $run->id, array_slice($fingerprints, 4, 4), false),
            new ReviewParserDiagnosticsJob($payload->id, $run->id, array_slice($fingerprints, 8, 1), true),
        ]);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Running, $run->refresh()->status);
    }

    public function test_it_completes_a_manual_run_without_dispatching_when_no_diagnostics_remain(): void
    {
        Bus::fake();
        [$payload] = $this->payloadWithErrors(0);
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Queued,
        ]);

        app()->call([new DispatchParserDiagnosticReviewBatchesJob($payload->id, $run->id), 'handle']);

        Bus::assertNothingDispatched();
        $this->assertSame(ParserDiagnosticReviewRunStatus::Completed, $run->refresh()->status);
    }

    public function test_it_does_not_dispatch_reviews_for_current_ai_authoritative_output(): void
    {
        Bus::fake();
        [$payload] = $this->payloadWithErrors(1);
        $execution = ParserExecution::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'idempotency_key' => hash('sha256', 'ai-authoritative-dispatch'),
            'requested_engine' => ParserEngine::Ai,
            'selected_engine' => ParserEngine::Ai,
            'status' => 'completed',
            'payload_hash' => $payload->payload_hash,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $payload->update(['authoritative_parser_execution_id' => $execution->id]);

        app()->call([new DispatchParserDiagnosticReviewBatchesJob($payload->id), 'handle']);

        Bus::assertNothingDispatched();
    }

    public function test_it_is_unique_per_payload_and_manual_run(): void
    {
        $automaticJob = new DispatchParserDiagnosticReviewBatchesJob(123);
        $manualJob = new DispatchParserDiagnosticReviewBatchesJob(123, 456);

        $this->assertSame('123', $automaticJob->uniqueId());
        $this->assertSame('123:review-run:456', $manualJob->uniqueId());
        $this->assertSame('database', $automaticJob->connection);
        $this->assertSame('ai-parsing', $automaticJob->queue);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $automaticJob->timeout);
    }

    /** @return array{RawScrapePayload, list<string>} */
    private function payloadWithErrors(int $errorCount): array
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
            'payload' => '<p>Parser diagnostic batching fixture.</p>',
            'payload_hash' => hash('sha256', 'batch-payload'),
            'fetched_at' => now(),
        ]);
        $fingerprints = [];

        for ($index = 0; $index < $errorCount; $index++) {
            $fingerprint = hash('sha256', "batch-diagnostic-{$index}");
            $fingerprints[] = $fingerprint;
            ParserError::query()->create([
                'raw_scrape_payload_id' => $payload->id,
                'scrape_source_id' => $source->id,
                'target_date' => $payload->target_date,
                'error_type' => 'unknown_species_alias',
                'raw_field' => 'species',
                'raw_value' => "Unknown Fish {$index}",
                'message' => 'Unknown species alias.',
                'context' => [
                    'source' => $source->slug,
                    'date' => '2026-07-12',
                    'sanitized_paragraph' => "1 Unknown Fish {$index}.",
                ],
                'report_fingerprint' => hash('sha256', "batch-report-{$index}"),
                'diagnostic_fingerprint' => $fingerprint,
            ]);
        }

        return [$payload, $fingerprints];
    }
}
