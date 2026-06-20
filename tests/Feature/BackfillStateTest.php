<?php

namespace Tests\Feature;

use App\Enums\BackfillRunStatus;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\BackfillRunJob;
use App\Jobs\BackfillSourceDateJob;
use App\Models\BackfillRun;
use App\Models\BackfillRunItem;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
                && str_contains($html, '1 of 2 source dates complete')
                && str_contains($html, '50%')
                && ! str_contains($html, route('admin.backfills.poll')));
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
