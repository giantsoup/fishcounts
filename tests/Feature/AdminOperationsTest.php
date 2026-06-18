<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_source_operational_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.sources.update', $source), [
                'priority' => 5,
                'rate_limit_seconds' => 30,
                'is_enabled' => '1',
                'supports_historical_dates' => '1',
                'supports_landing_filter' => '1',
                'notes' => 'Verified source.',
            ])
            ->assertRedirect(route('admin.sources.index'));

        $this->assertDatabaseHas('scrape_sources', [
            'id' => $source->id,
            'priority' => 5,
            'rate_limit_seconds' => 30,
            'is_enabled' => true,
            'supports_historical_dates' => true,
            'supports_landing_filter' => true,
            'notes' => 'Verified source.',
        ]);
    }

    public function test_admin_can_view_failed_jobs(): void
    {
        $admin = User::factory()->admin()->create();

        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-job-uuid',
            'connection' => 'redis',
            'queue' => 'scraping',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
            'exception' => "RuntimeException: Failed source\nStack trace",
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.failed-jobs.index'))
            ->assertOk()
            ->assertSee('App\\Jobs\\ScrapeSourceForDateJob')
            ->assertSee('RuntimeException: Failed source');
    }
}
