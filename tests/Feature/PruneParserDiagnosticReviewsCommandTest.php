<?php

namespace Tests\Feature;

use App\Enums\ScrapeRunType;
use App\Models\ParserDiagnosticReview;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneParserDiagnosticReviewsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_command_keeps_three_complete_calendar_months_plus_the_current_month(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 00:05:00');
        config()->set('fish.ai_review.retention.complete_months', 3);
        $payload = $this->payload();
        $january = $this->review($payload, 'january', '2026-01-31 23:59:59');
        $february = $this->review($payload, 'february', '2026-02-01 00:00:00');
        $april = $this->review($payload, 'april', '2026-04-15 12:00:00');
        $may = $this->review($payload, 'may', '2026-05-01 00:01:00');

        $this->artisan('ai-reviews:prune')
            ->expectsOutput('Pruned 1 parser diagnostic review records.')
            ->assertSuccessful();

        $this->assertModelMissing($january);
        $this->assertModelExists($february);
        $this->assertModelExists($april);
        $this->assertModelExists($may);
    }

    private function review(RawScrapePayload $payload, string $fingerprint, string $createdAt): ParserDiagnosticReview
    {
        return ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'diagnostic_fingerprint' => hash('sha256', $fingerprint),
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function payload(): RawScrapePayload
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-05-01',
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-05-01',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<p>Fish count.</p>',
            'payload_hash' => hash('sha256', 'payload'),
            'fetched_at' => now(),
        ]);
    }
}
