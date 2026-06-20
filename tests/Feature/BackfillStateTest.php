<?php

namespace Tests\Feature;

use App\Enums\BackfillReparseRunStatus;
use App\Enums\BackfillRunStatus;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\BackfillRunJob;
use App\Jobs\BackfillSourceDateJob;
use App\Jobs\ReparseBackfillPayloadJob;
use App\Jobs\ReparseBackfillRunJob;
use App\Models\BackfillReparseRun;
use App\Models\BackfillRun;
use App\Models\BackfillRunItem;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BackfillStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_item_tracks_unavailable_source_response_and_finalizes_parent(): void
    {
        Http::fake([
            'www.fishermanslanding.com/*' => Http::response('', 204),
        ]);

        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [$source->id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Running,
        ]);
        $item = BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
        ]);

        app(BackfillSourceDateJob::class, ['backfillRunItemId' => $item->id])->handle();

        $this->assertDatabaseHas('backfill_run_items', [
            'id' => $item->id,
            'status' => BackfillRunStatus::Unavailable->value,
        ]);
        $this->assertDatabaseHas('backfill_runs', [
            'id' => $backfill->id,
            'status' => BackfillRunStatus::Succeeded->value,
            'unavailable_days' => 1,
        ]);
        $this->assertDatabaseHas('scrape_runs', [
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Backfill->value,
            'status' => 'unavailable',
        ]);
    }

    public function test_admin_can_pause_resume_and_retry_failed_backfill_items(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [$source->id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Running,
        ]);
        BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'status' => BackfillRunStatus::Failed,
            'error_message' => 'Timed out.',
        ]);

        $this->actingAs($admin)->post(route('admin.backfills.pause', $backfill))->assertRedirect(route('admin.backfills.index'));
        $this->assertDatabaseHas('backfill_runs', ['id' => $backfill->id, 'status' => BackfillRunStatus::Paused->value]);

        $this->actingAs($admin)->post(route('admin.backfills.resume', $backfill))->assertRedirect(route('admin.backfills.index'));
        $this->assertDatabaseHas('backfill_runs', ['id' => $backfill->id, 'status' => BackfillRunStatus::Running->value]);

        $this->actingAs($admin)->post(route('admin.backfills.retry-failed', $backfill))->assertRedirect(route('admin.backfills.index'));
        $this->assertDatabaseHas('backfill_run_items', [
            'backfill_run_id' => $backfill->id,
            'status' => BackfillRunStatus::Pending->value,
            'error_message' => null,
        ]);
    }

    public function test_admin_can_start_backfill_with_american_date_fields(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
            'is_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.backfills.store'), [
                'from_date' => '01/05/2026',
                'to_date' => '01/06/2026',
                'source_ids' => [$source->id],
                'batch_size_days' => 7,
            ])
            ->assertRedirect(route('admin.backfills.index'));

        $backfill = BackfillRun::query()->firstOrFail();

        $this->assertSame('2026-01-05', $backfill->from_date->toDateString());
        $this->assertSame('2026-01-06', $backfill->to_date->toDateString());
        $this->assertSame(2, $backfill->total_days);
        $this->assertSame(
            ['2026-01-05', '2026-01-06'],
            $backfill->items()->orderBy('target_date')->get()->map(fn (BackfillRunItem $item): string => $item->target_date->toDateString())->all(),
        );
        $this->assertDatabaseMissing('backfill_runs', [
            'from_date' => '01/05/2026',
        ]);
        Queue::assertPushed(BackfillRunJob::class);
    }

    public function test_admin_can_poll_backfill_progress(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-06',
            'source_ids' => [$source->id],
            'total_days' => 2,
            'status' => BackfillRunStatus::Running,
        ]);
        BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'status' => BackfillRunStatus::Succeeded,
        ]);
        BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-06',
            'status' => BackfillRunStatus::Pending,
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.backfills.poll'));

        $response
            ->assertOk()
            ->assertJsonPath('has_active_backfills', true)
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, '#'.$backfill->id)
                && str_contains($html, route('admin.backfills.show', $backfill))
                && str_contains($html, '1 of 2 source dates complete')
                && str_contains($html, '50%')
                && ! str_contains($html, route('admin.backfills.poll')));
    }

    public function test_admin_can_navigate_from_backfill_to_source_date_payloads(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [$source->id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Succeeded,
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Backfill,
            'target_date' => '2026-01-05',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'status' => BackfillRunStatus::Succeeded,
            'scrape_run_id' => $scrapeRun->id,
            'raw_scrape_payload_id' => $payload->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.backfills.index'))
            ->assertOk()
            ->assertSee(route('admin.backfills.show', $backfill));

        $this->actingAs($admin)
            ->get(route('admin.backfills.show', $backfill))
            ->assertOk()
            ->assertSee('Source dates')
            ->assertSee(route('admin.scrape-runs.show', $scrapeRun))
            ->assertSee(route('admin.raw-payloads.show', $payload));
    }

    public function test_admin_can_queue_backfill_payload_reparse(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [$source->id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Succeeded,
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Backfill,
            'target_date' => '2026-01-05',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'status' => BackfillRunStatus::Succeeded,
            'scrape_run_id' => $scrapeRun->id,
            'raw_scrape_payload_id' => $payload->id,
        ]);

        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.backfills.reparse', $backfill))
            ->assertRedirect(route('admin.backfills.show', $backfill))
            ->assertSessionHas('status', 'Backfill reparse #1 queued for 1 saved payload(s).');

        $reparseRun = BackfillReparseRun::query()->firstOrFail();

        $this->assertSame(BackfillReparseRunStatus::Pending, $reparseRun->status);
        $this->assertSame(1, $reparseRun->total_payloads);
        $this->assertSame(0, $reparseRun->queued_payloads);
        Queue::assertPushed(ReparseBackfillRunJob::class, fn (ReparseBackfillRunJob $job): bool => $job->backfillReparseRunId === $reparseRun->id);
    }

    public function test_admin_sees_no_payload_message_when_backfill_has_nothing_saved_to_reparse(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [$source->id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Succeeded,
        ]);
        BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'status' => BackfillRunStatus::Succeeded,
        ]);

        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.backfills.reparse', $backfill))
            ->assertRedirect(route('admin.backfills.show', $backfill))
            ->assertSessionHas('status', 'No saved raw payloads are available to reparse for this backfill.');

        $this->assertDatabaseHas('backfill_reparse_runs', [
            'backfill_run_id' => $backfill->id,
            'status' => BackfillReparseRunStatus::Succeeded->value,
            'total_payloads' => 0,
        ]);
        Queue::assertNotPushed(ReparseBackfillRunJob::class);

        $this->actingAs($admin)
            ->get(route('admin.backfills.show', $backfill))
            ->assertOk()
            ->assertSee('Runs the parser again against the 0 raw payload(s) already saved for this backfill.');
    }

    public function test_normal_user_cannot_queue_backfill_reparse(): void
    {
        $user = User::factory()->create();
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $user->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [],
            'total_days' => 0,
            'status' => BackfillRunStatus::Succeeded,
        ]);

        $this->actingAs($user)->post(route('admin.backfills.reparse', $backfill))->assertForbidden();
    }

    public function test_reparse_backfill_run_job_dispatches_saved_payload_jobs(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [$source->id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Succeeded,
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Backfill,
            'target_date' => '2026-01-05',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        BackfillRunItem::query()->create([
            'backfill_run_id' => $backfill->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'status' => BackfillRunStatus::Succeeded,
            'scrape_run_id' => $scrapeRun->id,
            'raw_scrape_payload_id' => $payload->id,
        ]);
        $reparseRun = BackfillReparseRun::query()->create([
            'backfill_run_id' => $backfill->id,
            'created_by_user_id' => $admin->id,
        ]);

        Queue::fake();

        app(ReparseBackfillRunJob::class, ['backfillReparseRunId' => $reparseRun->id])->handle();

        $reparseRun->refresh();

        $this->assertSame(BackfillReparseRunStatus::Running, $reparseRun->status);
        $this->assertSame(1, $reparseRun->total_payloads);
        $this->assertSame(1, $reparseRun->queued_payloads);
        Queue::assertPushed(ReparseBackfillPayloadJob::class, fn (ReparseBackfillPayloadJob $job): bool => $job->backfillReparseRunId === $reparseRun->id
            && $job->rawScrapePayloadId === $payload->id);
    }

    public function test_admin_can_poll_backfill_reparse_progress(): void
    {
        $admin = User::factory()->admin()->create();
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [],
            'total_days' => 0,
            'status' => BackfillRunStatus::Succeeded,
        ]);
        BackfillReparseRun::query()->create([
            'backfill_run_id' => $backfill->id,
            'created_by_user_id' => $admin->id,
            'status' => BackfillReparseRunStatus::Running,
            'total_payloads' => 42,
            'queued_payloads' => 42,
            'completed_payloads' => 10,
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.backfills.reparse-poll', $backfill))
            ->assertOk()
            ->assertJsonPath('has_active_reparse', true)
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Reparse saved payloads')
                && str_contains($html, 'running')
                && str_contains($html, '10'));
    }

    public function test_reparse_payload_job_records_failure_progress_and_finishes_run(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $backfill = BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [$source->id],
            'total_days' => 1,
            'status' => BackfillRunStatus::Succeeded,
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Backfill,
            'target_date' => '2026-01-05',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        $reparseRun = BackfillReparseRun::query()->create([
            'backfill_run_id' => $backfill->id,
            'created_by_user_id' => $admin->id,
            'status' => BackfillReparseRunStatus::Running,
            'total_payloads' => 1,
            'queued_payloads' => 1,
            'started_at' => now(),
        ]);

        (new ReparseBackfillPayloadJob($reparseRun->id, $payload->id))->failed(new RuntimeException('Parser failed.'));

        $reparseRun->refresh();
        $payload->refresh();

        $this->assertSame(BackfillReparseRunStatus::Failed, $reparseRun->status);
        $this->assertSame(1, $reparseRun->failed_payloads);
        $this->assertNotNull($reparseRun->finished_at);
        $this->assertSame('Parser failed.', $payload->error_message);
    }

    public function test_backfill_progress_polling_stops_when_no_backfills_are_active(): void
    {
        $admin = User::factory()->admin()->create();

        BackfillRun::query()->create([
            'created_by_user_id' => $admin->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-05',
            'source_ids' => [],
            'total_days' => 0,
            'status' => BackfillRunStatus::Succeeded,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.backfills.poll'))
            ->assertOk()
            ->assertJsonPath('has_active_backfills', false);
    }

    public function test_normal_user_cannot_poll_backfill_progress(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('admin.backfills.poll'))->assertForbidden();
    }
}
