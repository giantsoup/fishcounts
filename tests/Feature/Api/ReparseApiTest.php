<?php

namespace Tests\Feature\Api;

use App\Enums\ParserReparseItemMode;
use App\Enums\ParserReparseItemStatus;
use App\Enums\ParserReparseRunStatus;
use App\Jobs\DispatchParserReparseRunJob;
use App\Models\ParserError;
use App\Models\ParserReparseRun;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReparseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_preview_returns_the_scope_and_a_64_character_fingerprint(): void
    {
        $source = ScrapeSource::factory()->create();
        $this->openErrorOn($source, '2026-07-10');
        $this->openErrorOn($source, '2026-07-20', structural: true);
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-10');

        $response = $this->withToken($this->token(['reparse']))
            ->getJson('/api/reparse-runs/preview?'.http_build_query(['source' => $source->id, 'from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertJsonPath('data.open_errors', 2)
            ->assertJsonPath('data.alias_errors', 1)
            ->assertJsonPath('data.payloads', 2)
            ->assertJsonPath('data.dates', 2)
            ->assertJsonPath('data.source', $source->id)
            ->assertJsonPath('data.from', '2026-07-01')
            ->assertJsonPath('data.to', '2026-07-31');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('data.fingerprint'));
        $this->assertArrayNotHasKey('items', $response->json('data'));
    }

    public function test_start_with_the_previewed_fingerprint_creates_and_queues_a_run(): void
    {
        $source = ScrapeSource::factory()->create();
        $this->openErrorOn($source, '2026-07-10');
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-10');
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('agent', ['reparse'])->plainTextToken;
        $scope = ['source' => $source->id, 'from' => '2026-07-01', 'to' => '2026-07-31'];
        $fingerprint = $this->withToken($token)->getJson('/api/reparse-runs/preview?'.http_build_query($scope))->json('data.fingerprint');

        $response = $this->withToken($token)
            ->postJson('/api/reparse-runs', [...$scope, 'fingerprint' => $fingerprint])
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.finished', false)
            ->assertJsonPath('data.initial.open_errors', 1)
            ->assertJsonPath('data.progress.total_items', 1)
            ->assertJsonPath('data.requested_by_user_id', $admin->id);

        $run = ParserReparseRun::query()->sole();
        $this->assertSame($run->id, $response->json('data.id'));
        $this->assertSame($admin->id, $run->requested_by_user_id);
        Queue::assertPushed(DispatchParserReparseRunJob::class, fn (DispatchParserReparseRunJob $job): bool => $job->parserReparseRunId === $run->id);
    }

    public function test_start_with_a_stale_fingerprint_is_rejected_and_creates_nothing(): void
    {
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-10');
        $token = $this->token(['reparse']);
        $fingerprint = $this->withToken($token)->getJson('/api/reparse-runs/preview')->json('data.fingerprint');
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-11');

        $this->withToken($token)
            ->postJson('/api/reparse-runs', ['fingerprint' => $fingerprint])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');

        $this->assertSame(0, ParserReparseRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_start_requires_a_64_character_fingerprint(): void
    {
        $token = $this->token(['reparse']);

        $this->withToken($token)->postJson('/api/reparse-runs')->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->withToken($token)->postJson('/api/reparse-runs', ['fingerprint' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->withToken($token)->postJson('/api/reparse-runs', ['fingerprint' => str_repeat('a', 64), 'source' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('source');
    }

    public function test_start_while_a_run_is_active_returns_that_run_without_queueing_another(): void
    {
        $active = ParserReparseRun::query()->create(['status' => ParserReparseRunStatus::Running]);
        $token = $this->token(['reparse']);
        $fingerprint = $this->withToken($token)->getJson('/api/reparse-runs/preview')->json('data.fingerprint');

        $this->withToken($token)
            ->postJson('/api/reparse-runs', ['fingerprint' => $fingerprint])
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('data.id', $active->id)
            ->assertJsonPath('data.status', 'running');

        Queue::assertNothingPushed();
    }

    public function test_status_returns_structured_json_with_progress_failures_and_timestamps(): void
    {
        $payload = RawScrapePayload::factory()->create(['target_date' => '2026-07-10']);
        $run = ParserReparseRun::query()->create([
            'status' => ParserReparseRunStatus::Failed,
            'initial_open_errors' => 5,
            'initial_alias_errors' => 2,
            'initial_structural_errors' => 3,
            'initial_payloads' => 2,
            'affected_dates' => 1,
            'total_items' => 2,
            'completed_items' => 1,
            'failed_items' => 1,
            'remaining_open_errors' => 4,
            'remaining_alias_errors' => 1,
            'remaining_structural_errors' => 3,
            'error_message' => '1 item(s) failed to reparse.',
            'started_at' => '2026-07-11 10:00:00',
            'finished_at' => '2026-07-11 10:05:00',
        ]);
        $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => '2026-07-10',
            'mode' => ParserReparseItemMode::Authoritative,
            'sequence' => 1,
            'status' => ParserReparseItemStatus::Failed,
            'attempts' => 3,
            'error_message' => 'Upstream timeout; api_key=sk-live-123 rejected',
        ]);
        $run->items()->create([
            'raw_scrape_payload_id' => RawScrapePayload::factory()->create()->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => '2026-07-10',
            'mode' => ParserReparseItemMode::DiagnosticsOnly,
            'sequence' => 2,
            'status' => ParserReparseItemStatus::Succeeded,
        ]);

        $response = $this->withToken($this->token(['read']))
            ->getJson("/api/reparse-runs/{$run->id}")
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $run->id,
                'status' => 'failed',
                'finished' => true,
                'requested_by_user_id' => null,
                'initial' => ['open_errors' => 5, 'alias_errors' => 2, 'structural_errors' => 3, 'payloads' => 2, 'dates' => 1],
                'progress' => ['total_items' => 2, 'queued_items' => 0, 'completed_items' => 1, 'failed_items' => 1],
                'remaining' => ['open_errors' => 4, 'alias_errors' => 1, 'structural_errors' => 3],
                'error_message' => '1 item(s) failed to reparse.',
                'failures' => [[
                    'raw_scrape_payload_id' => $payload->id,
                    'scrape_source_id' => $payload->scrape_source_id,
                    'target_date' => '2026-07-10',
                    'mode' => 'authoritative',
                    'attempts' => 3,
                    'error_message' => 'Upstream timeout; api_key=[redacted] rejected',
                ]],
                'started_at' => '2026-07-11T10:00:00+00:00',
                'finished_at' => '2026-07-11T10:05:00+00:00',
                'created_at' => $run->created_at->toIso8601String(),
                'updated_at' => $run->updated_at->toIso8601String(),
            ]]);

        $this->assertStringNotContainsString('sk-live-123', $response->getContent());
    }

    public function test_status_of_a_missing_run_is_a_json_404(): void
    {
        $this->withToken($this->token(['read']))->getJson('/api/reparse-runs/999')->assertNotFound();
    }

    public function test_retry_requeues_the_failed_items_of_a_failed_run(): void
    {
        $run = $this->failedRun();

        $this->withToken($this->token(['reparse']))
            ->postJson("/api/reparse-runs/{$run->id}/retry")
            ->assertOk()
            ->assertJsonPath('meta.retried', true)
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.progress.failed_items', 0)
            ->assertJsonPath('data.failures', []);

        $this->assertSame(ParserReparseItemStatus::Pending, $run->items()->sole()->status);
        Queue::assertPushed(DispatchParserReparseRunJob::class, fn (DispatchParserReparseRunJob $job): bool => $job->parserReparseRunId === $run->id);
    }

    public function test_retry_of_a_run_without_failures_changes_nothing(): void
    {
        $run = ParserReparseRun::query()->create(['status' => ParserReparseRunStatus::Succeeded, 'finished_at' => now()]);

        $this->withToken($this->token(['reparse']))
            ->postJson("/api/reparse-runs/{$run->id}/retry")
            ->assertOk()
            ->assertJsonPath('meta.retried', false)
            ->assertJsonPath('data.status', 'succeeded');

        Queue::assertNothingPushed();
    }

    public function test_retry_of_a_missing_run_is_a_json_404(): void
    {
        $this->withToken($this->token(['reparse']))->postJson('/api/reparse-runs/999/retry')->assertNotFound();
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities): string
    {
        return User::factory()->admin()->create()->createToken('agent', $abilities)->plainTextToken;
    }

    private function failedRun(): ParserReparseRun
    {
        $payload = RawScrapePayload::factory()->create(['target_date' => '2026-07-10']);
        $run = ParserReparseRun::query()->create([
            'status' => ParserReparseRunStatus::Failed,
            'total_items' => 1,
            'queued_items' => 1,
            'failed_items' => 1,
            'finished_at' => now(),
            'error_message' => '1 item(s) failed to reparse.',
        ]);
        $run->items()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => '2026-07-10',
            'mode' => ParserReparseItemMode::Authoritative,
            'sequence' => 1,
            'status' => ParserReparseItemStatus::Failed,
            'error_message' => 'Timed out.',
        ]);

        return $run;
    }

    private function openErrorOn(ScrapeSource $source, string $targetDate, bool $structural = false): ParserError
    {
        $factory = ParserError::factory()->for(RawScrapePayload::factory()->for($source)->state(['target_date' => $targetDate]));

        return ($structural ? $factory->structural() : $factory)->create();
    }
}
