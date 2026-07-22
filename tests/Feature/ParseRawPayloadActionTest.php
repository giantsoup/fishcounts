<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParseRawPayloadResult;
use App\Enums\BackfillReparseRunStatus;
use App\Enums\BackfillRunStatus;
use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ScrapeRunType;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\ParseRawPayloadJob;
use App\Jobs\ReparseBackfillPayloadJob;
use App\Models\BackfillReparseRun;
use App\Models\BackfillRun;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\TripReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ParseRawPayloadActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_returns_a_typed_result_and_is_idempotent(): void
    {
        $payload = $this->createPayload('2026-07-01');
        $action = app(ParseRawPayloadAction::class);
        Queue::fake();

        $firstResult = $action->handle($payload->id);
        $secondResult = $action->handle($payload->id, false);

        $this->assertInstanceOf(ParseRawPayloadResult::class, $firstResult);
        $this->assertSame($payload->id, $firstResult->rawScrapePayloadId);
        $this->assertSame('source-specific-fishermans_landing-v3', $firstResult->parserVersion);
        $this->assertSame(1, $firstResult->parsedReportCount);
        $this->assertSame(
            ParserError::query()->where('raw_scrape_payload_id', $payload->id)->whereNull('resolution_type')->count(),
            $firstResult->diagnosticCount,
        );
        $this->assertTrue($firstResult->shouldDispatchDeduplication);
        $this->assertSame($firstResult->rawScrapePayloadId, $secondResult->rawScrapePayloadId);
        $this->assertSame($firstResult->parserVersion, $secondResult->parserVersion);
        $this->assertSame($firstResult->parsedReportCount, $secondResult->parsedReportCount);
        $this->assertSame($firstResult->diagnosticCount, $secondResult->diagnosticCount);
        $this->assertFalse($secondResult->shouldDispatchDeduplication);
        $this->assertSame(1, TripReport::query()->where('raw_scrape_payload_id', $payload->id)->count());
        Queue::assertPushed(DeduplicateTripReportsJob::class, 1);
        Queue::assertPushed(DeduplicateTripReportsJob::class, fn (DeduplicateTripReportsJob $job): bool => $job->date === '2026-07-01');
    }

    public function test_normal_and_backfill_jobs_use_equivalent_parsing_paths(): void
    {
        $normalPayload = $this->createPayload('2026-07-01');
        $backfillPayload = $this->createPayload('2026-07-02');
        $admin = User::factory()->admin()->create();
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-07-02',
            'to_date' => '2026-07-02',
            'source_ids' => [$backfillPayload->scrape_source_id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Succeeded,
        ]);
        $reparseRun = BackfillReparseRun::query()->create([
            'backfill_run_id' => $backfill->id,
            'created_by_user_id' => $admin->id,
            'status' => BackfillReparseRunStatus::Running,
            'total_payloads' => 1,
            'queued_payloads' => 1,
            'started_at' => now(),
        ]);
        $action = app(ParseRawPayloadAction::class);
        Queue::fake();

        (new ParseRawPayloadJob($normalPayload->id))->handle($action);
        (new ReparseBackfillPayloadJob($reparseRun->id, $backfillPayload->id))->handle($action);

        $normalReport = TripReport::query()->where('raw_scrape_payload_id', $normalPayload->id)->firstOrFail();
        $backfillReport = TripReport::query()->where('raw_scrape_payload_id', $backfillPayload->id)->firstOrFail();

        $this->assertSame($normalReport->raw_boat_name, $backfillReport->raw_boat_name);
        $this->assertSame($normalReport->raw_trip_type, $backfillReport->raw_trip_type);
        $this->assertSame($normalReport->anglers, $backfillReport->anglers);
        $this->assertSame($normalReport->raw_fish_count_text, $backfillReport->raw_fish_count_text);
        $this->assertSame($normalReport->metadata['parser'], $backfillReport->metadata['parser']);
        $this->assertSame(
            $normalReport->speciesCounts()->orderBy('species_id')->pluck('count', 'species_id')->all(),
            $backfillReport->speciesCounts()->orderBy('species_id')->pluck('count', 'species_id')->all(),
        );
        $this->assertSame('source-specific-fishermans_landing-v3', $normalPayload->refresh()->parser_version);
        $this->assertSame($normalPayload->parser_version, $backfillPayload->refresh()->parser_version);
        $this->assertSame(1, $reparseRun->refresh()->completed_payloads);
        $this->assertSame(BackfillReparseRunStatus::Succeeded, $reparseRun->status);
        Queue::assertPushed(DeduplicateTripReportsJob::class, 2);
    }

    public function test_job_configuration_and_failure_behavior_remain_unchanged(): void
    {
        $payload = $this->createPayload('2026-07-01');
        $reviewRun = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Preparing,
        ]);
        $parseJob = new ParseRawPayloadJob($payload->id, true, $reviewRun->id);
        $backfillJob = new ReparseBackfillPayloadJob(42, $payload->id);
        $failureMessage = Str::repeat('x', 1200);

        $this->assertSame('parsing', $parseJob->queue);
        $this->assertSame(3, $parseJob->tries);
        $this->assertSame(120, $parseJob->timeout);
        $this->assertSame("{$payload->id}:review-run:{$reviewRun->id}", $parseJob->uniqueId());
        $this->assertSame((string) $payload->id, (new ParseRawPayloadJob($payload->id))->uniqueId());
        $this->assertSame([30, 120, 300], $parseJob->backoff());
        $this->assertSame('parsing', $backfillJob->queue);
        $this->assertSame(3, $backfillJob->tries);
        $this->assertSame(120, $backfillJob->timeout);
        $this->assertSame("42:{$payload->id}", $backfillJob->uniqueId());
        $this->assertSame([30, 120, 300], $backfillJob->backoff());

        $parseJob->failed(new RuntimeException($failureMessage));

        $this->assertSame(1003, Str::length($payload->refresh()->error_message));
        $this->assertStringEndsWith('...', $payload->error_message);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $reviewRun->refresh()->status);
    }

    public function test_normal_parsing_job_is_idempotent(): void
    {
        $payload = $this->createPayload('2026-07-01');
        $job = new ParseRawPayloadJob($payload->id);
        $action = app(ParseRawPayloadAction::class);
        Queue::fake();

        $job->handle($action);
        $firstDiagnosticCount = ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->whereNull('resolution_type')
            ->count();
        $job->handle($action);

        $this->assertSame(1, TripReport::query()->where('raw_scrape_payload_id', $payload->id)->count());
        $this->assertSame(
            $firstDiagnosticCount,
            ParserError::query()
                ->where('raw_scrape_payload_id', $payload->id)
                ->whereNull('resolution_type')
                ->count(),
        );
        Queue::assertPushed(DeduplicateTripReportsJob::class, 1);
    }

    private function createPayload(string $targetDate): RawScrapePayload
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
            'target_date' => $targetDate,
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $targetDate,
            'url' => "https://www.fishermanslanding.com/fishcounts.php?date={$targetDate}",
            'payload' => '<p>Dolphin Full Day 20 anglers 40 Yellowtail</p>',
            'payload_hash' => hash('sha256', "fixture-{$targetDate}"),
            'fetched_at' => now(),
        ]);
    }
}
