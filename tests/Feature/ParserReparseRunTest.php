<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\Actions\Parsing\RefreshParserReparseRunProgress;
use App\Actions\Parsing\RefreshRawPayloadDiagnostics;
use App\Actions\Parsing\StartParserReparseRun;
use App\DTOs\ParseRawPayloadOptions;
use App\DTOs\ParseRawPayloadResult;
use App\Enums\ParserErrorResolutionType;
use App\Enums\ParserReparseItemMode;
use App\Enums\ParserReparseItemStatus;
use App\Enums\ParserReparseRunStatus;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Jobs\DispatchParserReparseRunJob;
use App\Jobs\ProcessParserReparseDateJob;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserError;
use App\Models\ParserReparseItem;
use App\Models\ParserReparseRun;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\User;
use App\Services\Parsing\TripReportNormalizer;
use Database\Seeders\DatabaseSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ParserReparseRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_page_explains_the_safe_reparse_workflow_and_non_admins_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $payload = $this->payload($this->source(), '2026-07-20', 'open error payload');
        $this->parserError($payload, 'unaccounted_numeric_tokens');
        $resolvedError = $this->parserError($payload, 'resolved_structural_error');
        $resolvedError->update([
            'resolved_at' => now(),
            'resolution_type' => ParserErrorResolutionType::Dismissed,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSee('Reparse open errors')
            ->assertSee('aria-label="Open parser errors: 1"', false)
            ->assertSee('aria-label="All parser errors: 2"', false)
            ->assertSee('No sources will be scraped.')
            ->assertSee('Canonical aliases will not be created or dismissed.')
            ->assertSee('Parser-version changes may invalidate stale report overrides');

        $this->actingAs($user)
            ->post(route('admin.parser-errors.reparse-runs.store'))
            ->assertForbidden();
    }

    public function test_start_route_uses_csrf_and_throttle_middleware(): void
    {
        $middleware = app('router')
            ->getRoutes()
            ->getByName('admin.parser-errors.reparse-runs.store')
            ->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertContains('throttle:3,1', $middleware);

        $pollMiddleware = app('router')
            ->getRoutes()
            ->getByName('admin.parser-errors.reparse-runs.poll')
            ->gatherMiddleware();
        $this->assertContains('throttle:60,1', $pollMiddleware);
    }

    public function test_duplicate_submissions_return_one_active_run_and_queue_one_coordinator(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $payload = $this->payload($this->source(), '2026-07-20', 'duplicate submission');
        $this->parserError($payload, 'unaccounted_numeric_tokens');

        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'))->assertRedirect();

        $this->assertSame(1, ParserReparseRun::query()->count());
        Queue::assertPushed(DispatchParserReparseRunJob::class, 1);
    }

    public function test_manifest_refreshes_affected_superseded_payloads_and_parses_the_newest_payload_authoritatively(): void
    {
        Queue::fake();
        $source = $this->source();
        $admin = User::factory()->admin()->create();
        $oldest = $this->payload($source, '2026-07-20', 'oldest', now()->subMinutes(2));
        $affectedNewest = $this->payload($source, '2026-07-20', 'affected newest', now()->subMinute());
        $authoritative = $this->payload($source, '2026-07-20', 'authoritative', now());
        $this->parserError($oldest, 'unaccounted_numeric_tokens');
        $this->parserError($affectedNewest, 'unknown_species_alias');
        ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-20',
            'error_type' => 'missing_payload_reference',
            'message' => 'Legacy diagnostic without a payload.',
        ]);

        $result = app(StartParserReparseRun::class)->handle($admin);
        $items = $result->run->items()->orderBy('sequence')->get();

        $this->assertTrue($result->created);
        $this->assertSame(3, $result->run->initial_open_errors);
        $this->assertSame(2, $result->run->initial_payloads);
        $this->assertSame(
            [
                [$oldest->id, ParserReparseItemMode::DiagnosticsOnly],
                [$affectedNewest->id, ParserReparseItemMode::DiagnosticsOnly],
                [$authoritative->id, ParserReparseItemMode::Authoritative],
            ],
            $items->map(fn (ParserReparseItem $item): array => [$item->raw_scrape_payload_id, $item->mode])->all(),
        );
    }

    public function test_a_run_with_no_eligible_payloads_finishes_without_queueing_work(): void
    {
        Queue::fake();
        $source = $this->source();
        ParserError::query()->create([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-20',
            'error_type' => 'legacy_error',
            'message' => 'No payload is attached.',
        ]);

        $result = app(StartParserReparseRun::class)->handle(User::factory()->admin()->create());

        $this->assertSame(ParserReparseRunStatus::Succeeded, $result->run->status);
        $this->assertSame(0, $result->run->total_items);
        Queue::assertNotPushed(DispatchParserReparseRunJob::class);
    }

    public function test_diagnostic_only_refresh_does_not_mutate_reports_or_payload_parse_metadata_or_dispatch_jobs(): void
    {
        Queue::fake();
        $source = $this->source('fishermans_landing');
        $payload = $this->payload(
            $source,
            '2026-07-20',
            '<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>',
        );
        $before = $payload->only(['parsed_at', 'parser_version']);

        app(RefreshRawPayloadDiagnostics::class)->handle($payload->id);

        $this->assertSame($before, $payload->fresh()->only(['parsed_at', 'parser_version']));
        $this->assertDatabaseCount('trip_reports', 0);
        $this->assertDatabaseHas('parser_errors', [
            'raw_scrape_payload_id' => $payload->id,
            'error_type' => 'unknown_species_alias',
            'raw_value' => 'Moon Fish',
        ]);
        Queue::assertNotPushed(DeduplicateTripReportsJob::class);
        Queue::assertNotPushed(DispatchParserDiagnosticReviewBatchesJob::class);
    }

    public function test_date_job_processes_diagnostics_then_authoritative_payload_then_deduplicates(): void
    {
        $source = $this->source();
        $old = $this->payload($source, '2026-07-20', 'old', now()->subMinute());
        $new = $this->payload($source, '2026-07-20', 'new', now());
        $run = $this->runningRun();
        $this->item($run, $old, ParserReparseItemMode::DiagnosticsOnly, 1);
        $this->item($run, $new, ParserReparseItemMode::Authoritative, 2);
        $run->update(['total_items' => 2]);

        $refreshDiagnostics = $this->mock(RefreshRawPayloadDiagnostics::class, function (MockInterface $mock) use ($old): void {
            $mock->shouldReceive('handle')->once()->with($old->id)->ordered()->andReturn(0);
        });
        $parse = $this->mock(ParseRawPayloadAction::class, function (MockInterface $mock) use ($new): void {
            $mock->shouldReceive('handleWithOptions')
                ->once()
                ->with($new->id, \Mockery::on(fn (ParseRawPayloadOptions $options): bool => ! $options->dispatchDeduplication && ! $options->dispatchDiagnosticReviews))
                ->ordered()
                ->andReturn($this->parseResult($new->id));
        });
        $normalizer = $this->mock(TripReportNormalizer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshPrimaryReports')->once()->with('2026-07-20')->ordered();
        });

        (new ProcessParserReparseDateJob($run->id, '2026-07-20'))->handle(
            $refreshDiagnostics,
            $parse,
            $normalizer,
            app(RefreshParserReparseRunProgress::class),
        );

        $this->assertSame(ParserReparseRunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->completed_items);
        $this->assertNotNull($run->items()->where('mode', ParserReparseItemMode::Authoritative)->firstOrFail()->date_deduplicated_at);
    }

    public function test_coordinator_queues_one_unique_job_per_affected_date(): void
    {
        Queue::fake();
        $source = $this->source();
        $run = ParserReparseRun::query()->create(['status' => ParserReparseRunStatus::Pending]);
        $this->item($run, $this->payload($source, '2026-07-19', 'first date'), ParserReparseItemMode::Authoritative, 1);
        $this->item($run, $this->payload($source, '2026-07-20', 'second date'), ParserReparseItemMode::Authoritative, 2);

        (new DispatchParserReparseRunJob($run->id))->handle(app(RefreshParserReparseRunProgress::class));

        $this->assertSame(ParserReparseRunStatus::Running, $run->fresh()->status);
        Queue::assertPushed(ProcessParserReparseDateJob::class, 2);
        Queue::assertPushed(ProcessParserReparseDateJob::class, fn (ProcessParserReparseDateJob $job): bool => $job->uniqueId() === $run->id.':2026-07-19');
        Queue::assertPushed(ProcessParserReparseDateJob::class, fn (ProcessParserReparseDateJob $job): bool => $job->uniqueId() === $run->id.':2026-07-20');
    }

    public function test_queue_jobs_use_bounded_retries_and_timeout_below_database_retry_after(): void
    {
        $coordinator = new DispatchParserReparseRunJob(1);
        $dateJob = new ProcessParserReparseDateJob(1, '2026-07-20');
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertSame(3, $coordinator->tries);
        $this->assertSame([30, 120, 300], $coordinator->backoff());
        $this->assertSame(3, $dateJob->tries);
        $this->assertSame([30, 120, 300], $dateJob->backoff());
        $this->assertSame(120, $dateJob->timeout);
        $this->assertLessThan($retryAfter, $dateJob->timeout);
    }

    public function test_date_job_failure_is_bounded_durable_and_retryable_without_recounting_successes(): void
    {
        $source = $this->source();
        $diagnosticPayload = $this->payload($source, '2026-07-20', 'diagnostics', now()->subMinute());
        $authoritativePayload = $this->payload($source, '2026-07-20', 'failure', now());
        $run = $this->runningRun();
        $successfulItem = $this->item($run, $diagnosticPayload, ParserReparseItemMode::DiagnosticsOnly, 1);
        $failedItem = $this->item($run, $authoritativePayload, ParserReparseItemMode::Authoritative, 2);
        $run->update(['total_items' => 2]);
        $refresh = $this->mock(RefreshRawPayloadDiagnostics::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturn(0);
        });
        $parse = $this->mock(ParseRawPayloadAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handleWithOptions')->once()->andThrow(new RuntimeException(str_repeat('failure ', 200)));
        });
        $job = new ProcessParserReparseDateJob($run->id, '2026-07-20');

        try {
            $job->handle(
                $refresh,
                $parse,
                $this->mock(TripReportNormalizer::class),
                app(RefreshParserReparseRunProgress::class),
            );
            $this->fail('The simulated parser failure should be rethrown.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(ParserReparseItemStatus::Succeeded, $successfulItem->fresh()->status);
        $this->assertSame(ParserReparseItemStatus::Pending, $failedItem->fresh()->status);
        $this->assertSame(1, $failedItem->fresh()->attempts);

        $job->failed(new RuntimeException(str_repeat('failure ', 200)));

        $this->assertSame(ParserReparseItemStatus::Succeeded, $successfulItem->fresh()->status);
        $this->assertSame(ParserReparseItemStatus::Failed, $failedItem->fresh()->status);
        $this->assertLessThanOrEqual(1000, mb_strlen((string) $failedItem->fresh()->error_message));
        $this->assertSame(ParserReparseRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->completed_items);
        $this->assertSame(1, $run->fresh()->failed_items);
    }

    public function test_terminal_run_is_a_no_op_on_job_redelivery(): void
    {
        $run = ParserReparseRun::query()->create(['status' => ParserReparseRunStatus::Succeeded]);
        $refresh = $this->mock(RefreshRawPayloadDiagnostics::class);
        $parse = $this->mock(ParseRawPayloadAction::class);
        $normalizer = $this->mock(TripReportNormalizer::class);
        $progress = $this->mock(RefreshParserReparseRunProgress::class);
        $refresh->shouldNotReceive('handle');
        $parse->shouldNotReceive('handleWithOptions');
        $normalizer->shouldNotReceive('refreshPrimaryReports');
        $progress->shouldNotReceive('handle');

        (new ProcessParserReparseDateJob($run->id, '2026-07-20'))->handle($refresh, $parse, $normalizer, $progress);

        $this->assertSame(ParserReparseRunStatus::Succeeded, $run->fresh()->status);
    }

    public function test_a_new_payload_arriving_during_a_run_is_appended_and_processed_last(): void
    {
        $source = $this->source();
        $old = $this->payload($source, '2026-07-20', 'old', now()->subMinute());
        $run = $this->runningRun();
        $this->item($run, $old, ParserReparseItemMode::Authoritative, 1);
        $new = null;
        $processedPayloadIds = [];
        $this->mock(RefreshRawPayloadDiagnostics::class)->shouldNotReceive('handle');
        $parse = $this->mock(ParseRawPayloadAction::class, function (MockInterface $mock) use ($source, &$new, &$processedPayloadIds): void {
            $mock->shouldReceive('handleWithOptions')->twice()->andReturnUsing(function (int $payloadId) use ($source, &$new, &$processedPayloadIds): ParseRawPayloadResult {
                $processedPayloadIds[] = $payloadId;

                if ($new === null) {
                    $new = $this->payload($source, '2026-07-20', 'new', now());
                }

                return $this->parseResult($payloadId);
            });
        });
        $normalizer = $this->mock(TripReportNormalizer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshPrimaryReports')->once();
        });

        (new ProcessParserReparseDateJob($run->id, '2026-07-20'))->handle(
            app(RefreshRawPayloadDiagnostics::class),
            $parse,
            $normalizer,
            app(RefreshParserReparseRunProgress::class),
        );

        $this->assertNotNull($new);
        $this->assertSame([$old->id, $new->id], $processedPayloadIds);
        $this->assertDatabaseHas('parser_reparse_items', [
            'parser_reparse_run_id' => $run->id,
            'raw_scrape_payload_id' => $new->id,
            'mode' => ParserReparseItemMode::Authoritative->value,
            'status' => ParserReparseItemStatus::Succeeded->value,
        ]);
    }

    public function test_end_to_end_run_removes_repaired_structural_diagnostics_keeps_real_aliases_and_persists_newest_reports(): void
    {
        Queue::fake();
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Dolphin', 'slug' => 'dolphin']);
        $payload = $this->payload(
            $source,
            '2026-07-20',
            '<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>',
        );
        $this->parserError($payload, 'unaccounted_numeric_tokens');
        $run = $this->runningRun();
        $this->item($run, $payload, ParserReparseItemMode::Authoritative, 1);
        $run->update([
            'initial_open_errors' => 1,
            'initial_structural_errors' => 1,
            'total_items' => 1,
        ]);

        app()->call([new ProcessParserReparseDateJob($run->id, '2026-07-20'), 'handle']);

        $this->assertDatabaseMissing('parser_errors', [
            'raw_scrape_payload_id' => $payload->id,
            'error_type' => 'unaccounted_numeric_tokens',
        ]);
        $this->assertDatabaseHas('parser_errors', [
            'raw_scrape_payload_id' => $payload->id,
            'error_type' => 'unknown_species_alias',
            'raw_value' => 'Moon Fish',
        ]);
        $this->assertDatabaseHas('trip_reports', ['raw_scrape_payload_id' => $payload->id]);
        $this->assertSame(ParserReparseRunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->remaining_structural_errors);
        $this->assertGreaterThanOrEqual(1, $run->fresh()->remaining_alias_errors);
        Queue::assertNotPushed(DeduplicateTripReportsJob::class);
        Queue::assertNotPushed(DispatchParserDiagnosticReviewBatchesJob::class);
    }

    public function test_failed_item_retry_preserves_successful_items_and_queues_only_remaining_work(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $successfulPayload = $this->payload($source, '2026-07-19', 'success');
        $failedPayload = $this->payload($source, '2026-07-20', 'failure');
        $run = ParserReparseRun::query()->create([
            'requested_by_user_id' => $admin->id,
            'status' => ParserReparseRunStatus::Failed,
            'total_items' => 2,
            'completed_items' => 1,
            'failed_items' => 1,
            'finished_at' => now(),
        ]);
        $successful = $this->item($run, $successfulPayload, ParserReparseItemMode::Authoritative, 1, ParserReparseItemStatus::Succeeded);
        $failed = $this->item($run, $failedPayload, ParserReparseItemMode::Authoritative, 2, ParserReparseItemStatus::Failed);
        $originalStartedAt = now()->subMinutes(5)->startOfSecond();
        $failed->update(['attempts' => 3, 'started_at' => $originalStartedAt]);

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reparse-runs.retry', $run))
            ->assertRedirect(route('admin.parser-errors.index'));

        $this->assertSame(ParserReparseItemStatus::Succeeded, $successful->fresh()->status);
        $this->assertSame(ParserReparseItemStatus::Pending, $failed->fresh()->status);
        $this->assertSame(3, $failed->fresh()->attempts);
        $this->assertTrue($originalStartedAt->equalTo($failed->fresh()->started_at));
        $this->assertSame(ParserReparseRunStatus::Pending, $run->fresh()->status);
        Queue::assertPushed(DispatchParserReparseRunJob::class, 1);
    }

    public function test_failed_run_cannot_be_retried_while_another_global_run_is_active(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $failedRun = ParserReparseRun::query()->create([
            'requested_by_user_id' => $admin->id,
            'status' => ParserReparseRunStatus::Failed,
            'total_items' => 1,
            'failed_items' => 1,
        ]);
        $failedItem = $this->item(
            $failedRun,
            $this->payload($source, '2026-07-20', 'failed payload'),
            ParserReparseItemMode::Authoritative,
            1,
            ParserReparseItemStatus::Failed,
        );
        ParserReparseRun::query()->create(['status' => ParserReparseRunStatus::Running]);

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reparse-runs.retry', $failedRun))
            ->assertRedirect(route('admin.parser-errors.index'))
            ->assertSessionHas('status', 'Another parser reparse run is active. Retry this run after it finishes.');

        $this->assertSame(ParserReparseRunStatus::Failed, $failedRun->fresh()->status);
        $this->assertSame(ParserReparseItemStatus::Failed, $failedItem->fresh()->status);
        Queue::assertNotPushed(DispatchParserReparseRunJob::class);
    }

    public function test_polling_returns_current_progress_for_admins_only(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $run = ParserReparseRun::query()->create([
            'requested_by_user_id' => $admin->id,
            'status' => ParserReparseRunStatus::Running,
            'initial_open_errors' => 4,
            'total_items' => 2,
            'completed_items' => 1,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.parser-errors.reparse-runs.poll', $run))
            ->assertOk()
            ->assertJsonPath('has_active_reparse', true)
            ->assertJsonPath('show_reparse_run', true)
            ->assertSee('Reparse run #'.$run->id, false)
            ->assertSee('1 of 2 items finished', false);

        $this->actingAs($user)
            ->getJson(route('admin.parser-errors.reparse-runs.poll', $run))
            ->assertForbidden();
    }

    public function test_succeeded_run_is_retired_from_the_page_and_polling_panel(): void
    {
        $admin = User::factory()->admin()->create();
        $run = ParserReparseRun::query()->create([
            'requested_by_user_id' => $admin->id,
            'status' => ParserReparseRunStatus::Succeeded,
            'total_items' => 2,
            'completed_items' => 2,
            'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSee('Reparse run #'.$run->id)
            ->assertDontSee(route('admin.parser-errors.reparse-runs.poll', $run));

        $this->actingAs($admin)
            ->getJson(route('admin.parser-errors.reparse-runs.poll', $run))
            ->assertOk()
            ->assertJsonPath('has_active_reparse', false)
            ->assertJsonPath('show_reparse_run', false)
            ->assertJsonPath('html', '');
    }

    public function test_failed_run_remains_visible_for_retry(): void
    {
        $admin = User::factory()->admin()->create();
        $run = ParserReparseRun::query()->create([
            'requested_by_user_id' => $admin->id,
            'status' => ParserReparseRunStatus::Failed,
            'total_items' => 2,
            'completed_items' => 1,
            'failed_items' => 1,
            'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSee('Reparse run #'.$run->id)
            ->assertSee('Retry failed items')
            ->assertSee(route('admin.parser-errors.reparse-runs.retry', $run));

        $this->actingAs($admin)
            ->getJson(route('admin.parser-errors.reparse-runs.poll', $run))
            ->assertOk()
            ->assertJsonPath('has_active_reparse', false)
            ->assertJsonPath('show_reparse_run', true)
            ->assertSee('Retry failed items', false);
    }

    private function source(string $slug = 'test_parser_reparse_source'): ScrapeSource
    {
        return ScrapeSource::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => str($slug)->headline(),
                'source_type' => SourceType::Landing,
                'base_url' => 'https://example.test/'.$slug,
            ],
        );
    }

    private function payload(ScrapeSource $source, string $date, string $body, DateTimeInterface|string|null $fetchedAt = null): RawScrapePayload
    {
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => $date,
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $date,
            'url' => $source->base_url,
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => $fetchedAt ?? now(),
        ]);
    }

    private function parserError(RawScrapePayload $payload, string $type): ParserError
    {
        return ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'error_type' => $type,
            'raw_field' => 'payload',
            'raw_value' => $payload->payload,
            'message' => str($type)->headline(),
            'diagnostic_fingerprint' => hash('sha256', $payload->id.$type),
        ]);
    }

    private function runningRun(): ParserReparseRun
    {
        return ParserReparseRun::query()->create([
            'status' => ParserReparseRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    private function item(
        ParserReparseRun $run,
        RawScrapePayload $payload,
        ParserReparseItemMode $mode,
        int $sequence,
        ParserReparseItemStatus $status = ParserReparseItemStatus::Pending,
    ): ParserReparseItem {
        return $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'mode' => $mode,
            'sequence' => $sequence,
            'status' => $status,
        ]);
    }

    private function parseResult(int $payloadId): ParseRawPayloadResult
    {
        return new ParseRawPayloadResult(
            rawScrapePayloadId: $payloadId,
            parserVersion: 'test',
            parsedReportCount: 1,
            diagnosticCount: 0,
            shouldDispatchDeduplication: false,
        );
    }
}
