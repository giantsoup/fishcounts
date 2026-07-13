<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\Enums\HistoricalAiReviewRunItemStatus;
use App\Enums\HistoricalAiReviewRunStatus;
use App\Enums\ParserBugReportStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserErrorResolutionType;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\CreateParserBugIssueJob;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\ProcessHistoricalAiReviewRunItemJob;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use App\Models\Boat;
use App\Models\HistoricalAiReviewRun;
use App\Models\Landing;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\TripReport;
use App\Models\User;
use App\Services\AI\AiReviewMetrics;
use App\Services\AI\HistoricalAiReviewDispatcher;
use App\Services\Parsing\TripReportNormalizer;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhaseNineOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_historical_dry_run_is_bounded_and_does_not_write_or_dispatch(): void
    {
        $this->payloadWithDiagnostic('2026-06-01');
        Queue::fake();

        $this->artisan('ai-reviews:dispatch', [
            'scope' => 'historical',
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--max' => '10',
            '--budget-micros' => '2000000',
            '--dry-run' => true,
        ])->expectsOutput('Dry run only; no records were written and no jobs were dispatched.')
            ->assertSuccessful();

        $this->assertDatabaseCount('historical_ai_review_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_authorized_run_uses_date_count_and_budget_bounds_with_idempotent_items(): void
    {
        $this->payloadWithDiagnostic('2026-06-01', 'first');
        $this->payloadWithDiagnostic('2026-06-02', 'second');
        $this->payloadWithDiagnostic('2026-06-03', 'third');
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('fish.ai_review.budgets.estimated_request_cost_micros', 1_000_000);
        Queue::fake();

        $this->artisan('ai-reviews:dispatch', [
            'scope' => 'historical',
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--max' => '3',
            '--budget-micros' => '2000000',
            '--authorized-by' => 'approved phase-9 batch 1',
        ])->assertSuccessful();

        $run = HistoricalAiReviewRun::query()->sole();
        $this->assertSame(2, $run->selected_count);
        $this->assertSame(2_000_000, $run->budget_micros);
        $this->assertSame('approved phase-9 batch 1', $run->authorization_reference);
        $this->assertDatabaseCount('historical_ai_review_run_items', 2);
        Queue::assertPushed(ProcessHistoricalAiReviewRunItemJob::class, 2);

        $item = $run->items()->firstOrFail();
        $this->expectException(QueryException::class);
        $run->items()->create([
            'raw_scrape_payload_id' => $item->raw_scrape_payload_id,
            'payload_hash' => $item->payload_hash,
            'item_fingerprint' => $item->item_fingerprint,
        ]);
    }

    public function test_every_execution_requires_a_separate_authorization_reference(): void
    {
        $this->payloadWithDiagnostic('2026-06-01');
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');

        $this->artisan('ai-reviews:dispatch', [
            'scope' => 'historical',
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--max' => '1',
            '--budget-micros' => '1000000',
        ])->expectsOutput('--authorized-by must be a unique approval reference for this execution and cannot exceed 255 characters.')
            ->assertExitCode(2);

        $this->assertDatabaseCount('historical_ai_review_runs', 0);
    }

    public function test_replaying_the_same_authorization_returns_the_existing_run(): void
    {
        $this->payloadWithDiagnostic('2026-06-01', 'authorization-replay');
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        Queue::fake();
        $arguments = [
            'scope' => 'historical',
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--max' => '1',
            '--budget-micros' => '1000000',
            '--authorized-by' => 'phase-9-replay-safe-authorization',
        ];

        $this->artisan('ai-reviews:dispatch', $arguments)->assertSuccessful();
        $this->artisan('ai-reviews:dispatch', $arguments)->assertSuccessful();
        $differentBounds = $arguments;
        $differentBounds['--max'] = '2';
        $this->artisan('ai-reviews:dispatch', $differentBounds)
            ->expectsOutput('The authorization reference is already assigned to a run with different bounds.')
            ->assertExitCode(2);

        $this->assertDatabaseCount('historical_ai_review_runs', 1);
        $this->assertDatabaseCount('historical_ai_review_run_items', 1);
        Queue::assertPushed(ProcessHistoricalAiReviewRunItemJob::class, 1);
    }

    public function test_new_unresolved_and_historical_selectors_follow_the_approved_definitions(): void
    {
        $this->payloadWithDiagnostic('2026-06-01', 'never-reviewed');
        $retryablePayload = $this->payloadWithDiagnostic('2026-06-02', 'retryable');
        $completedPayload = $this->payloadWithDiagnostic('2026-06-03', 'completed');
        $this->review($retryablePayload, ParserDiagnosticReviewStatus::Failed);
        $this->review($completedPayload, ParserDiagnosticReviewStatus::Succeeded);
        $dispatcher = app(HistoricalAiReviewDispatcher::class);
        $from = CarbonImmutable::parse('2026-06-01');
        $to = CarbonImmutable::parse('2026-06-30');

        $this->assertSame(1, $dispatcher->preview('new', $from, $to, 10, 10_000_000)['eligible_count']);
        $this->assertSame(2, $dispatcher->preview('unresolved', $from, $to, 10, 10_000_000)['eligible_count']);
        $this->assertSame(2, $dispatcher->preview('historical', $from, $to, 10, 10_000_000)['eligible_count']);
    }

    public function test_run_can_pause_resume_and_gracefully_stop_queued_work(): void
    {
        $payload = $this->payloadWithDiagnostic('2026-06-01');
        $run = $this->historicalRun(HistoricalAiReviewRunStatus::Running, 1);
        $item = $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'item_fingerprint' => hash('sha256', $payload->id.'|'.$payload->payload_hash),
        ]);
        Queue::fake();

        $this->artisan('ai-reviews:historical', ['run' => $run->id, 'action' => 'pause'])->assertSuccessful();
        $this->assertSame(HistoricalAiReviewRunStatus::Paused, $run->refresh()->status);

        $this->artisan('ai-reviews:historical', ['run' => $run->id, 'action' => 'resume'])->assertSuccessful();
        $this->assertSame(HistoricalAiReviewRunItemStatus::Pending, $item->refresh()->status);
        Queue::assertPushed(ProcessHistoricalAiReviewRunItemJob::class);

        $this->artisan('ai-reviews:historical', ['run' => $run->id, 'action' => 'stop'])->assertSuccessful();
        $this->assertSame(HistoricalAiReviewRunStatus::Stopped, $run->refresh()->status);

        app()->call([new ProcessHistoricalAiReviewRunItemJob($item->id), 'handle']);
        $this->assertSame(HistoricalAiReviewRunItemStatus::Pending, $item->refresh()->status);
        $this->assertModelExists($run);
        $this->assertModelExists($item);
        $this->assertStringStartsWith('test authorization-', $run->authorization_reference);
    }

    public function test_resuming_a_paused_run_finalizes_items_that_finished_while_paused(): void
    {
        Queue::fake();
        $successfulRun = $this->historicalRun(HistoricalAiReviewRunStatus::Paused, 1);
        $successfulRun->forceFill(['completed_count' => 1, 'paused_at' => now()])->save();
        $failedRun = $this->historicalRun(HistoricalAiReviewRunStatus::Paused, 1);
        $failedRun->forceFill(['failed_count' => 1, 'paused_at' => now()])->save();

        $this->artisan('ai-reviews:historical', ['run' => $successfulRun->id, 'action' => 'resume'])->assertSuccessful();
        $this->artisan('ai-reviews:historical', ['run' => $failedRun->id, 'action' => 'resume'])->assertSuccessful();

        $this->assertSame(HistoricalAiReviewRunStatus::Completed, $successfulRun->refresh()->status);
        $this->assertNotNull($successfulRun->completed_at);
        $this->assertSame(HistoricalAiReviewRunStatus::Failed, $failedRun->refresh()->status);
        $this->assertNotNull($failedRun->completed_at);
        Queue::assertNothingPushed();
    }

    public function test_a_run_with_terminal_item_failures_is_marked_failed(): void
    {
        $payload = $this->payloadWithDiagnostic('2026-06-01', 'terminal-failure');
        $run = $this->historicalRun(HistoricalAiReviewRunStatus::Running, 1);
        $item = $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'item_fingerprint' => hash('sha256', 'terminal-failure-item'),
        ]);

        (new ProcessHistoricalAiReviewRunItemJob($item->id))->failed(new \RuntimeException('Provider remained unavailable.'));

        $this->assertSame(HistoricalAiReviewRunItemStatus::Failed, $item->refresh()->status);
        $this->assertSame(HistoricalAiReviewRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(1, $run->failed_count);
    }

    public function test_historical_retry_cannot_cross_its_authorized_run_budget(): void
    {
        $payload = $this->payloadWithDiagnostic('2026-06-01', 'retry-budget');
        $run = $this->historicalRun(HistoricalAiReviewRunStatus::Running, 1);
        $run->forceFill(['estimated_spent_micros' => 1_000_000])->save();
        $item = $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'item_fingerprint' => hash('sha256', 'retry-budget-item'),
            'status' => HistoricalAiReviewRunItemStatus::Running,
            'attempts' => 1,
        ]);
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');

        app()->call([new ProcessHistoricalAiReviewRunItemJob($item->id), 'handle']);

        $this->assertSame(HistoricalAiReviewRunItemStatus::Failed, $item->refresh()->status);
        $this->assertSame(1, $item->attempts);
        $this->assertSame(1_000_000, $run->refresh()->estimated_spent_micros);
        $this->assertSame(HistoricalAiReviewRunStatus::Failed, $run->status);
    }

    public function test_historical_run_stops_before_provider_use_when_cost_configuration_exceeds_authorization(): void
    {
        $payload = $this->payloadWithDiagnostic('2026-06-01', 'cost-drift');
        $run = $this->historicalRun(HistoricalAiReviewRunStatus::Running, 1);
        $item = $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'item_fingerprint' => hash('sha256', 'cost-drift-item'),
        ]);
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('fish.ai_review.budgets.estimated_request_cost_micros', 2_000_000);

        app()->call([new ProcessHistoricalAiReviewRunItemJob($item->id), 'handle']);

        $this->assertSame(HistoricalAiReviewRunItemStatus::Failed, $item->refresh()->status);
        $this->assertSame(0, $item->attempts);
        $this->assertSame(0, $run->refresh()->estimated_spent_micros);
        $this->assertSame(HistoricalAiReviewRunStatus::Failed, $run->status);
    }

    public function test_admin_dashboard_exposes_metrics_budgets_and_in_app_warnings(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payloadWithDiagnostic('2026-06-01');
        ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'diagnostic_fingerprint' => hash('sha256', 'review'),
            'payload_hash' => $payload->payload_hash,
            'status' => ParserDiagnosticReviewStatus::Failed,
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'failure_category' => 'schema_validation',
            'failed_at' => now(),
        ]);
        ParserBugReport::factory()->create([
            'status' => ParserBugReportStatus::Failed,
            'attempts' => 1,
            'last_attempted_at' => now(),
            'failure_message' => 'GitHub unavailable.',
        ]);
        DB::table('jobs')->insert([
            'queue' => 'github-issues',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(11)->timestamp,
        ]);
        config()->set('fish.ai_review.operations.failure_warning', 1);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('AI review operations need attention')
            ->assertSee('Succeeded / failed / refused')
            ->assertSee('Schema failures / stale total')
            ->assertSee('GitHub queue / oldest')
            ->assertSee('GitHub issue failures are above the 24-hour warning threshold.')
            ->assertSee('$5.00 remaining')
            ->assertSee('$50.00 limit');
    }

    public function test_ai_maintenance_schedules_use_overlap_and_single_server_guards(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['ai-reviews:prune', 'ai-reviews:monitor'] as $command) {
            $event = $events->first(fn ($event): bool => str_contains($event->command, $command));
            $this->assertNotNull($event);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }
    }

    public function test_historical_job_timeout_is_below_database_retry_after(): void
    {
        $job = new ProcessHistoricalAiReviewRunItemJob(1);

        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertSame('1', $job->uniqueId());
    }

    public function test_worker_restart_retries_a_running_item_without_duplicate_processing(): void
    {
        $payload = $this->payloadWithDiagnostic('2026-06-01', 'worker-restart');
        $payload->parserErrors()->update([
            'resolution_type' => ParserErrorResolutionType::Dismissed,
            'resolved_at' => now(),
        ]);
        $run = $this->historicalRun(HistoricalAiReviewRunStatus::Running, 1);
        $item = $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'item_fingerprint' => hash('sha256', 'worker-restart-item'),
            'status' => HistoricalAiReviewRunItemStatus::Running,
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');

        app()->call([new ProcessHistoricalAiReviewRunItemJob($item->id), 'handle']);
        app()->call([new ProcessHistoricalAiReviewRunItemJob($item->id), 'handle']);

        $this->assertSame(HistoricalAiReviewRunItemStatus::Completed, $item->refresh()->status);
        $this->assertSame(1, $item->attempts);
        $this->assertSame(1, $run->refresh()->completed_count);
        $this->assertSame(HistoricalAiReviewRunStatus::Completed, $run->status);
    }

    public function test_invalid_ai_credentials_fail_the_production_check_without_exposing_secrets(): void
    {
        config()->set('fish.ai_review.enabled', true);
        config()->set('services.openai.api_key', null);

        $this->artisan('fish:production-check', ['--skip-database' => true])
            ->expectsOutputToContain('OPENAI_API_KEY is required when AI reviews are enabled.')
            ->assertFailed();
    }

    public function test_github_outage_cannot_undo_deterministic_parsing_or_deduplication(): void
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
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
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-01',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php?date=2026-07-01',
            'payload' => '<p>Dolphin Full Day 20 anglers 40 Yellowtail</p>',
            'payload_hash' => hash('sha256', 'github-outage-parser-fixture'),
            'fetched_at' => now(),
        ]);
        Queue::fake();

        app(ParseRawPayloadAction::class)->handle($payload->id);
        (new DeduplicateTripReportsJob('2026-07-01'))->handle(app(TripReportNormalizer::class));
        $parserError = ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-01',
            'error_type' => 'extracted_value_source_span_mismatch',
            'message' => 'Parser issue queued for GitHub.',
            'diagnostic_fingerprint' => hash('sha256', 'github-outage-diagnostic'),
        ]);
        $review = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'payload_hash' => $payload->payload_hash,
            'status' => ParserDiagnosticReviewStatus::Succeeded,
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'completed_at' => now(),
        ]);
        $report = ParserBugReport::factory()->create([
            'parser_diagnostic_review_id' => $review->id,
            'status' => ParserBugReportStatus::Pending,
        ]);
        $review->forceFill(['parser_bug_report_id' => $report->id])->save();

        (new CreateParserBugIssueJob($review->id))->failed(new \RuntimeException('GitHub unavailable.'));

        $tripReport = TripReport::query()->whereBelongsTo($payload, 'rawScrapePayload')->sole();
        $this->assertTrue($tripReport->is_deduped_primary);
        $this->assertModelExists($parserError);
        $this->assertSame(ParserBugReportStatus::Failed, $report->refresh()->status);
        Queue::assertPushed(DeduplicateTripReportsJob::class);
    }

    public function test_pruning_bounds_failure_metadata_budget_reservations_and_failed_jobs(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 00:05:00');
        $payload = $this->payloadWithDiagnostic('2026-01-01');
        $run = $this->historicalRun(HistoricalAiReviewRunStatus::Failed, 1);
        $run->forceFill(['failure_message' => 'bounded run failure', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01'])->save();
        $item = $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'item_fingerprint' => hash('sha256', 'old-item'),
            'status' => HistoricalAiReviewRunItemStatus::Failed,
            'failure_message' => 'bounded item failure',
            'created_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
        ]);
        $period = AiBudgetPeriod::factory()->create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'created_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
        ]);
        $reservation = AiBudgetReservation::factory()->create([
            'ai_budget_period_id' => $period->id,
            'status' => 'settled',
            'created_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
        ]);
        $retainedReview = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'diagnostic_fingerprint' => hash('sha256', 'retained-old-failure'),
            'payload_hash' => $payload->payload_hash,
            'status' => ParserDiagnosticReviewStatus::Failed,
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'failure_category' => 'schema_validation',
            'failure_message' => 'Old schema failure.',
            'total_tokens' => 100,
            'failed_at' => '2026-01-01',
            'created_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
        ]);
        $retainedReport = ParserBugReport::factory()->create([
            'parser_diagnostic_review_id' => $retainedReview->id,
            'created_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
        ]);
        $retainedReview->forceFill(['parser_bug_report_id' => $retainedReport->id])->save();
        DB::table('failed_jobs')->insert([
            'uuid' => 'old-phase-nine-failure',
            'connection' => 'database',
            'queue' => 'ai-parsing',
            'payload' => '{}',
            'exception' => 'bounded failure metadata',
            'failed_at' => '2026-01-01 00:00:00',
        ]);

        $this->artisan('ai-reviews:prune')->assertSuccessful();

        $this->assertNull($run->refresh()->failure_message);
        $this->assertNull($item->refresh()->failure_message);
        $this->assertModelMissing($reservation);
        $this->assertModelMissing($period);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'old-phase-nine-failure']);
        $this->assertModelExists($retainedReview);
        $this->assertNull($retainedReview->refresh()->failure_message);
        $metrics = app(AiReviewMetrics::class)->snapshot();
        $this->assertSame(0, $metrics['failed']);
        $this->assertSame(0, $metrics['schema_failures']);
        $this->assertSame(0, $metrics['tokens']);
    }

    public function test_daily_budget_backfill_accounts_for_existing_reservations_in_the_operational_timezone(): void
    {
        config()->set('fish.ai_review.budgets.timezone', 'America/Los_Angeles');
        $monthlyPeriod = AiBudgetPeriod::factory()->create([
            'period_type' => 'monthly',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);
        $reservation = AiBudgetReservation::factory()->create([
            'ai_budget_period_id' => $monthlyPeriod->id,
            'daily_ai_budget_period_id' => null,
            'status' => 'settled',
            'reserved_micros' => 1_000_000,
            'actual_micros' => 750_000,
            'reserved_at' => '2026-07-13 06:30:00',
            'settled_at' => '2026-07-13 06:31:00',
        ]);
        $migration = require database_path('migrations/2026_07_13_021937_backfill_daily_ai_budget_periods_for_existing_reservations.php');

        $migration->up();

        $dailyPeriod = AiBudgetPeriod::query()->where('period_type', 'daily')->sole();
        $this->assertSame('2026-07-12', $dailyPeriod->period_start->toDateString());
        $this->assertSame(750_000, $dailyPeriod->spent_micros);
        $this->assertSame($dailyPeriod->id, $reservation->refresh()->daily_ai_budget_period_id);
    }

    private function payloadWithDiagnostic(string $targetDate, string $suffix = 'payload'): RawScrapePayload
    {
        $source = ScrapeSource::query()->firstOrCreate(['slug' => 'phase-nine-'.$suffix], [
            'name' => 'Phase Nine '.$suffix,
            'source_type' => SourceType::Landing,
            'base_url' => 'https://example.test',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => $targetDate,
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $targetDate,
            'url' => 'https://example.test/'.$suffix,
            'payload' => '<p>Count.</p>',
            'payload_hash' => hash('sha256', $suffix),
            'fetched_at' => now(),
        ]);
        ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => $targetDate,
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Moon Fish',
            'message' => 'Unknown species alias.',
            'diagnostic_fingerprint' => hash('sha256', 'diagnostic-'.$suffix),
        ]);

        return $payload;
    }

    private function historicalRun(HistoricalAiReviewRunStatus $status, int $selectedCount): HistoricalAiReviewRun
    {
        return HistoricalAiReviewRun::query()->create([
            'scope' => 'historical',
            'status' => $status,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'max_items' => $selectedCount,
            'budget_micros' => $selectedCount * 1_000_000,
            'estimated_item_cost_micros' => 1_000_000,
            'authorization_reference' => 'test authorization-'.uniqid(),
            'selection_fingerprint' => hash('sha256', 'selection-'.uniqid()),
            'selected_count' => $selectedCount,
            'started_at' => now(),
        ]);
    }

    private function review(RawScrapePayload $payload, ParserDiagnosticReviewStatus $status): ParserDiagnosticReview
    {
        $parserError = $payload->parserErrors()->sole();

        return ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'payload_hash' => $payload->payload_hash,
            'status' => $status,
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
        ]);
    }
}
