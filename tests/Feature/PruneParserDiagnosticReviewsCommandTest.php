<?php

namespace Tests\Feature;

use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ScrapeRunType;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewAction;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\User;
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

    public function test_command_keeps_a_rolling_three_month_window(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 00:00:00');
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

    public function test_retention_prunes_whole_months_only_on_a_month_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-05-31 12:00:00');
        config()->set('fish.ai_review.retention.complete_months', 3);
        $payload = $this->payload();
        $outsideWindow = $this->review($payload, 'outside-window', '2026-01-31 23:59:59');
        $retainedOldestMonth = $this->review($payload, 'retained-oldest-month', '2026-02-01 00:00:00');
        $retainedLateInOldestMonth = $this->review($payload, 'retained-late-in-oldest-month', '2026-02-27 11:59:59');

        $this->artisan('ai-reviews:prune')->assertSuccessful();

        $this->assertModelMissing($outsideWindow);
        $this->assertModelExists($retainedOldestMonth);
        $this->assertModelExists($retainedLateInOldestMonth);
    }

    public function test_command_retains_human_review_audit_history_indefinitely(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 00:05:00');
        config()->set('fish.ai_review.retention.complete_months', 3);
        $payload = $this->payload();
        $review = $this->review($payload, 'human-reviewed', '2025-01-01 00:00:00');
        $parserError = ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Moon Fish',
            'message' => 'Unknown species alias.',
        ]);
        $actor = User::factory()->admin()->create();
        ParserDiagnosticReviewAction::query()->create([
            'parser_diagnostic_review_id' => $review->id,
            'parser_error_id' => $parserError->id,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'action' => 'left_open',
        ]);

        $this->artisan('ai-reviews:prune')
            ->expectsOutput('Pruned 0 parser diagnostic review records.')
            ->assertSuccessful();

        $this->assertModelExists($review);
        $this->assertDatabaseHas('parser_diagnostic_review_actions', [
            'parser_diagnostic_review_id' => $review->id,
            'actor_user_id' => $actor->id,
        ]);

        $payload->delete();

        $this->assertModelMissing($review);
        $this->assertDatabaseHas('parser_diagnostic_review_actions', [
            'parser_diagnostic_review_id' => null,
            'parser_error_id' => $parserError->id,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
        ]);
    }

    public function test_command_retains_manual_run_audit_and_clears_old_failure_details(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 00:05:00');
        config()->set('fish.ai_review.retention.complete_months', 3);
        $payload = $this->payload();
        $actor = User::factory()->admin()->create();
        $run = ParserDiagnosticReviewRun::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'requested_by_user_id' => $actor->id,
            'status' => ParserDiagnosticReviewRunStatus::Failed,
            'failure_message' => 'Old operational failure detail.',
            'failed_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->artisan('ai-reviews:prune')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $run->status);
        $this->assertSame($actor->id, $run->requested_by_user_id);
        $this->assertNull($run->failure_message);
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
