<?php

namespace Tests\Feature;

use App\Enums\BackfillRunStatus;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
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
}
