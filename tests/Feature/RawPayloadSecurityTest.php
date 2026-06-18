<?php

namespace Tests\Feature;

use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\ParseRawPayloadJob;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RawPayloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_raw_payload_view_escapes_payload_html(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-01-05',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fish-counts.php?date=2026-01-05',
            'payload' => '<script>alert("xss")</script><p>40 Yellowtail</p>',
            'payload_hash' => hash('sha256', 'payload'),
            'fetched_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.raw-payloads.show', $payload))
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
    }

    public function test_admin_can_queue_raw_payload_reparse(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();

        $this->actingAs($admin)
            ->post(route('admin.raw-payloads.reparse', $payload))
            ->assertRedirect(route('admin.raw-payloads.show', $payload));

        Queue::assertPushed(ParseRawPayloadJob::class, fn (ParseRawPayloadJob $job): bool => $job->rawScrapePayloadId === $payload->id);
    }

    private function payload(): RawScrapePayload
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-01-05',
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<script>alert("xss")</script><p>40 Yellowtail</p>',
            'payload_hash' => hash('sha256', 'payload'),
            'fetched_at' => now(),
        ]);
    }
}
