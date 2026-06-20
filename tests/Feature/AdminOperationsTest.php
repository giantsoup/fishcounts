<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
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

    public function test_admin_dashboard_distinguishes_parser_warnings_from_scrape_status(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-06-20',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);

        ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Mixed Rockfish',
            'message' => 'Unknown species alias [Mixed Rockfish].',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('succeeded')
            ->assertSee(route('admin.scrape-runs.show', $scrapeRun))
            ->assertSee('1 parser warning needs alias review')
            ->assertSee(route('admin.backfills.index'))
            ->assertSee(route('admin.scrape-runs.index'))
            ->assertSee(route('admin.parser-errors.index'));
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

    public function test_admin_can_navigate_from_parser_error_to_raw_payload_and_scrape_run(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-06-20',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);

        ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Mixed Rockfish',
            'message' => 'Unknown species alias [Mixed Rockfish].',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSee(route('admin.raw-payloads.show', $payload));

        $this->actingAs($admin)
            ->get(route('admin.raw-payloads.show', $payload))
            ->assertOk()
            ->assertSee(route('admin.scrape-runs.show', $scrapeRun))
            ->assertSee(route('admin.scrape-runs.index'));
    }
}
