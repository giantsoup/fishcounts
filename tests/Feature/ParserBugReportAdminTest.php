<?php

namespace Tests\Feature;

use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\Role;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\CreateParserBugIssueJob;
use App\Models\ParserBugReport;
use App\Models\ParserBugReportOccurrence;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\User;
use App\Services\IssueTracking\ParserBugIssueCandidateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ParserBugReportAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fish.ai_review.human_review_enabled', true);
        config()->set('fish.github_issues.enabled', true);
        config()->set('fish.github_issues.write_enabled', false);
    }

    public function test_only_admins_can_prepare_or_approve_parser_bug_reports(): void
    {
        [$parserError, $review] = $this->review();
        $report = ParserBugReport::factory()->create();
        $review->update(['parser_bug_report_id' => $report->id]);
        $user = User::factory()->create(['role' => Role::User]);

        $this->actingAs($user)
            ->post(route('admin.parser-errors.reviews.prepare-github-issue', [$parserError, $review]))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.parser-bug-reports.approve', $report))
            ->assertForbidden();

        $this->assertNull($report->refresh()->approved_at);
    }

    public function test_admin_can_queue_a_preview_and_approve_it_with_a_durable_actor_audit(): void
    {
        Queue::fake();
        [$parserError, $review] = $this->review();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.prepare-github-issue', [$parserError, $review]))
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(CreateParserBugIssueJob::class, fn (CreateParserBugIssueJob $job): bool => $job->parserDiagnosticReviewId === $review->id);

        $report = $this->reportForReview($review);
        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.parser-bug-reports.approve', $report))
            ->assertRedirect()
            ->assertSessionHas('status');

        $report->refresh();
        $this->assertNotNull($report->approved_at);
        $this->assertSame($admin->id, $report->approved_by_user_id);
        $this->assertSame($admin->name, $report->approved_by_name);
        $this->assertSame($admin->email, $report->approved_by_email);
        Queue::assertPushed(CreateParserBugIssueJob::class, 1);
    }

    public function test_admin_preview_escapes_issue_markdown_and_never_displays_credentials(): void
    {
        [$parserError, $review] = $this->review();
        $report = ParserBugReport::factory()->create([
            'title' => '<script>alert("title")</script>',
            'body' => '<img src=x onerror=alert("body")> Bearer provider-secret',
        ]);
        $review->update(['parser_bug_report_id' => $report->id]);
        config()->set('services.github_app.private_key_base64', 'private-key-secret');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSee(e($report->title), false)
            ->assertSee(e($report->body), false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('private-key-secret');
    }

    public function test_rejected_review_invalidates_its_unpublished_preview(): void
    {
        [$parserError, $review] = $this->review();
        $report = $this->reportForReview($review);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.reject', [$parserError, $review]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('invalidated', $report->refresh()->status->value);
        $this->assertSame(0, $report->occurrence_count);
        $this->assertNull($review->refresh()->parser_bug_report_id);
        $this->assertNotNull($report->occurrences()->sole()->invalidated_at);
    }

    public function test_stale_approved_preview_is_refreshed_before_reapproval(): void
    {
        Queue::fake();
        [$parserError, $review] = $this->review();
        $report = $this->reportForReview($review);
        $currentBody = $report->body;
        $previousApprover = User::factory()->admin()->create();
        $nextApprover = User::factory()->admin()->create();
        $report->forceFill([
            'status' => 'pending',
            'body' => 'Legacy parser-bug issue template.',
            'approved_at' => now()->subMinute(),
            'approved_by_user_id' => $previousApprover->id,
            'approved_by_name' => $previousApprover->name,
            'approved_by_email' => $previousApprover->email,
            'failure_message' => 'Previous failure.',
        ])->save();

        $this->actingAs($nextApprover)
            ->post(route('admin.parser-bug-reports.approve', $report))
            ->assertSessionHasErrors('review');

        $report->refresh();
        $this->assertSame('preview', $report->status->value);
        $this->assertSame($currentBody, $report->body);
        $this->assertNull($report->approved_at);
        $this->assertNull($report->approved_by_user_id);
        $this->assertNull($report->approved_by_name);
        $this->assertNull($report->approved_by_email);
        $this->assertNull($report->failure_message);
        Queue::assertNothingPushed();

        $this->actingAs($nextApprover)
            ->post(route('admin.parser-bug-reports.approve', $report))
            ->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame('pending', $report->status->value);
        $this->assertSame($nextApprover->id, $report->approved_by_user_id);
        Queue::assertPushed(CreateParserBugIssueJob::class, 1);
    }

    public function test_stale_automatic_report_refreshes_and_queues_in_one_action(): void
    {
        Queue::fake();
        [$parserError, $review] = $this->review();
        $report = $this->reportForReview($review);
        $currentBody = $report->body;
        $admin = User::factory()->admin()->create();
        $report->forceFill([
            'status' => 'failed',
            'requires_approval' => false,
            'body' => 'Legacy parser-bug issue template.',
            'failure_message' => 'Previous failure.',
        ])->save();

        $this->actingAs($admin)
            ->post(route('admin.parser-bug-reports.approve', $report))
            ->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame('pending', $report->status->value);
        $this->assertSame($currentBody, $report->body);
        $this->assertFalse($report->requires_approval);
        $this->assertSame($admin->id, $report->approved_by_user_id);
        $this->assertNull($report->failure_message);
        Queue::assertPushed(CreateParserBugIssueJob::class, 1);
    }

    public function test_retry_invalidates_the_previous_attempt_preview_and_clears_its_current_link(): void
    {
        Queue::fake();
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        [$parserError, $review] = $this->review();
        $report = $this->reportForReview($review);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.retry', [$parserError, $review]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ParserDiagnosticReviewStatus::Pending, $review->refresh()->status);
        $this->assertNull($review->parser_bug_report_id);
        $this->assertSame('invalidated', $report->refresh()->status->value);
        $this->assertSame(0, $report->occurrence_count);
        $this->assertNotNull($report->occurrences()->sole()->invalidated_at);
    }

    /** @return array{ParserError, ParserDiagnosticReview} */
    private function review(): array
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman’s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-12',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-12',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<p>25 Bass</p>',
            'payload_hash' => hash('sha256', 'payload'),
            'fetched_at' => now(),
            'parser_version' => 'source-specific-v1',
        ]);
        $parserError = ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-12',
            'error_type' => 'extracted_value_source_span_mismatch',
            'raw_field' => 'species_counts',
            'raw_value' => '25 Bass',
            'message' => 'The extracted values do not match.',
            'context' => [
                'source' => 'fishermans_landing',
                'url' => 'https://www.fishermanslanding.com/fishcounts.php',
                'parser_version' => 'source-specific-v1',
                'sanitized_paragraph' => '25 Bass',
                'extracted_fields' => ['species_counts' => [['species' => 'Bass', 'retained' => 26]]],
                'evidence' => ['span' => '25 Bass'],
            ],
            'diagnostic_fingerprint' => hash('sha256', 'diagnostic'),
        ]);
        $review = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'status' => ParserDiagnosticReviewStatus::Succeeded,
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'classification' => ParserDiagnosticReviewClassification::ValueExtractionError,
            'confidence' => 0.98,
            'validated_result' => [
                'classification' => 'value_extraction_error',
                'confidence' => 0.98,
                'rationale' => 'Wrong count.',
                'corrections' => [[
                    'operation' => 'set_species_count',
                    'report_index' => 0,
                    'field' => 'species_count',
                    'canonical_type' => 'species',
                    'canonical_id' => 1,
                    'value' => null,
                    'retained_count' => 25,
                    'released_count' => 0,
                ]],
            ],
            'completed_at' => now(),
        ]);

        return [$parserError, $review];
    }

    private function reportForReview(ParserDiagnosticReview $review): ParserBugReport
    {
        $candidate = app(ParserBugIssueCandidateFactory::class)->make($review);
        $seenAt = $review->completed_at ?? now();
        $report = ParserBugReport::factory()->create([
            'parser_diagnostic_review_id' => $review->id,
            'review_attempt' => $review->attempts,
            'signature' => $candidate->signature,
            'source_slug' => $candidate->sourceSlug,
            'title' => $candidate->title,
            'body' => $candidate->body,
            'labels' => $candidate->labels,
            'occurrence_count' => 1,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
        ParserBugReportOccurrence::factory()->create([
            'parser_bug_report_id' => $report->id,
            'parser_diagnostic_review_id' => $review->id,
            'parser_error_id' => $review->parser_error_id,
            'review_attempt' => $review->attempts,
            'seen_at' => $seenAt,
        ]);
        $review->forceFill(['parser_bug_report_id' => $report->id])->save();

        return $report;
    }
}
