<?php

namespace Tests\Feature;

use App\Enums\CanonicalEntityType;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserErrorResolutionType;
use App\Enums\Role;
use App\Enums\ScrapeRunType;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Jobs\ParseRawPayloadJob;
use App\Models\Boat;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ParserDiagnosticHumanReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fish.ai_review.human_review_enabled', true);
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'secret-provider-key');
    }

    public function test_admin_can_view_escaped_review_details_without_provider_credentials(): void
    {
        [$payload, $parserError] = $this->payloadAndError([
            'message' => '<script>alert("source")</script>',
            'context' => [
                'sanitized_paragraph' => '<img src=x onerror=alert("paragraph")>',
                'parser_version' => 'parser-v5',
            ],
        ]);
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species, [
            'rationale' => '<script>alert("model")</script>',
        ]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.parser-errors.index'));

        $response
            ->assertOk()
            ->assertSeeText('Legitimate Alias')
            ->assertSeeText('98.0% confidence')
            ->assertSeeText('Opaleye')
            ->assertSeeText('parser-v5')
            ->assertSeeText('gpt-5.6-luna')
            ->assertSeeText('150')
            ->assertSee(e($review->rationale), false)
            ->assertSee(e(data_get($parserError->context, 'sanitized_paragraph')), false)
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('secret-provider-key');
    }

    public function test_review_ui_is_hidden_behind_the_feature_flag(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $this->review($payload, $parserError, $species);
        config()->set('fish.ai_review.human_review_enabled', false);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSeeText('AI review')
            ->assertDontSeeText('Accept existing alias');
    }

    public function test_manual_ai_review_button_is_only_shown_for_an_eligible_error_without_a_review(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $admin = User::factory()->admin()->create();
        $route = route('admin.parser-errors.reviews.store', $parserError);

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSeeText('No AI review is available.')
            ->assertSeeText('Run AI review')
            ->assertSeeText('Starting the review. Please keep this page open until the request is confirmed.')
            ->assertSee('x-bind:disabled="submitting"', false)
            ->assertSeeInOrder([$route, 'name="_token"', 'Run AI review'], false);

        config()->set('fish.ai_review.enabled', false);

        $this->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSeeText('Run AI review')
            ->assertDontSee('action="'.$route.'"', false);

        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', false);

        $this->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSeeText('Run AI review')
            ->assertDontSee('action="'.$route.'"', false);

        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', null);

        $this->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSeeText('Run AI review')
            ->assertDontSee('action="'.$route.'"', false);

        config()->set('services.openai.api_key', 'secret-provider-key');
        $parserError->forceFill(['resolution_type' => ParserErrorResolutionType::Dismissed])->save();

        $this->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSeeText('Run AI review')
            ->assertDontSee('action="'.$route.'"', false);

        $parserError->forceFill(['resolution_type' => null])->save();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $this->review($payload, $parserError, $species);

        $this->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSeeText('Run AI review')
            ->assertDontSee('action="'.$route.'"', false);
    }

    public function test_admin_can_manually_queue_a_missing_ai_review_without_duplicate_dispatch(): void
    {
        Queue::fake();
        [$payload, $parserError] = $this->payloadAndError();
        $admin = User::factory()->admin()->create();
        $indexRoute = route('admin.parser-errors.index');
        $storeRoute = route('admin.parser-errors.reviews.store', $parserError);

        $this->actingAs($admin)
            ->from($indexRoute)
            ->post($storeRoute)
            ->assertRedirect($indexRoute)
            ->assertSessionHas('status', 'AI review queued.');

        $run = ParserDiagnosticReviewRun::query()->sole();
        $this->assertSame(ParserDiagnosticReviewRunStatus::Queued, $run->status);
        $this->assertSame($payload->id, $run->raw_scrape_payload_id);
        $this->assertSame($admin->id, $run->requested_by_user_id);

        $this->post($storeRoute)
            ->assertRedirect($indexRoute)
            ->assertSessionHas('status', 'An AI review is already queued or running for this payload.');

        $this->get($indexRoute)
            ->assertOk()
            ->assertSeeText('Queued')
            ->assertSeeText('Waiting for an AI review worker. It is safe to leave or refresh this page.')
            ->assertDontSee('action="'.$storeRoute.'"', false);

        Queue::assertPushedOn('ai-parsing', DispatchParserDiagnosticReviewBatchesJob::class);
        Queue::assertPushed(DispatchParserDiagnosticReviewBatchesJob::class, 1);
        Queue::assertPushed(
            DispatchParserDiagnosticReviewBatchesJob::class,
            fn (DispatchParserDiagnosticReviewBatchesJob $job): bool => $job->rawScrapePayloadId === $payload->id
                && $job->parserDiagnosticReviewRunId === $run->id,
        );
    }

    public function test_legacy_parser_error_without_a_fingerprint_queues_reparse_before_ai_review(): void
    {
        Queue::fake();
        [, $parserError] = $this->payloadAndError();
        $parserError->forceFill([
            'report_fingerprint' => null,
            'diagnostic_fingerprint' => null,
        ])->save();
        $admin = User::factory()->admin()->create();
        $indexRoute = route('admin.parser-errors.index');
        $storeRoute = route('admin.parser-errors.reviews.store', $parserError);

        $this->actingAs($admin)
            ->get($indexRoute)
            ->assertOk()
            ->assertSeeText('Run AI review')
            ->assertSeeText('This legacy error will be reparsed first to prepare it for AI review.');

        $this->from($indexRoute)
            ->post($storeRoute)
            ->assertRedirect($indexRoute)
            ->assertSessionHas('status', 'Payload queued for reparsing. AI review will run automatically if the diagnostic is still present.');

        $run = ParserDiagnosticReviewRun::query()->sole();
        $this->assertSame(ParserDiagnosticReviewRunStatus::Preparing, $run->status);

        $this->post($storeRoute)
            ->assertSessionHas('status', 'An AI review is already queued or running for this payload.');

        $this->get($indexRoute)
            ->assertOk()
            ->assertSeeText('Preparing')
            ->assertSeeText('The payload reparse is queued to prepare this diagnostic for AI review.')
            ->assertDontSee('action="'.$storeRoute.'"', false);

        Queue::assertPushedOn('parsing', ParseRawPayloadJob::class);
        Queue::assertPushed(ParseRawPayloadJob::class, 1);
        Queue::assertPushed(
            ParseRawPayloadJob::class,
            fn (ParseRawPayloadJob $job): bool => $job->parserDiagnosticReviewRunId === $run->id,
        );
        Queue::assertNotPushed(DispatchParserDiagnosticReviewBatchesJob::class);
    }

    public function test_stalled_manual_run_is_recoverable_without_duplicate_dispatch(): void
    {
        Queue::fake();
        config()->set('fish.ai_review.operations.manual_run_stale_minutes', 60);
        [$payload, $parserError] = $this->payloadAndError();
        $admin = User::factory()->admin()->create();
        $stalledRun = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'requested_by_user_id' => $admin->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(90),
        ]);
        $indexRoute = route('admin.parser-errors.index');
        $storeRoute = route('admin.parser-errors.reviews.store', $parserError);

        $this->actingAs($admin)
            ->get($indexRoute)
            ->assertOk()
            ->assertSeeText('The last AI review request appears stalled. It is safe to start a new review.')
            ->assertSee('action="'.$storeRoute.'"', false);

        $this->from($indexRoute)
            ->post($storeRoute)
            ->assertRedirect($indexRoute)
            ->assertSessionHas('status', 'AI review queued.');

        $newRun = ParserDiagnosticReviewRun::query()->whereKeyNot($stalledRun->id)->sole();
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $stalledRun->refresh()->status);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Queued, $newRun->status);
        Queue::assertPushed(
            DispatchParserDiagnosticReviewBatchesJob::class,
            fn (DispatchParserDiagnosticReviewBatchesJob $job): bool => $job->parserDiagnosticReviewRunId === $newRun->id,
        );
        Queue::assertPushed(DispatchParserDiagnosticReviewBatchesJob::class, 1);
    }

    public function test_dispatch_failure_is_reported_to_the_admin_and_marks_the_run_failed(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $admin = User::factory()->admin()->create();
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Queue storage is unavailable.'));

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.store', $parserError))
            ->assertSessionHasErrors('review')
            ->assertSessionMissing('status');

        $run = ParserDiagnosticReviewRun::query()->sole();
        $this->assertSame($payload->id, $run->raw_scrape_payload_id);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Failed, $run->status);
        $this->assertSame('Queue storage is unavailable.', $run->failure_message);
    }

    public function test_active_automatic_retry_hides_the_manual_retry_action(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species, [
            'status' => ParserDiagnosticReviewStatus::Failed,
            'failure_message' => 'The provider was temporarily unavailable.',
        ]);
        ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Running,
        ]);
        $retryRoute = route('admin.parser-errors.reviews.retry', [$parserError, $review]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSeeText('The last attempt failed, and an automatic retry is still scheduled.')
            ->assertDontSee('action="'.$retryRoute.'"', false);
    }

    public function test_completed_run_without_a_current_review_has_an_explicit_state(): void
    {
        [$payload] = $this->payloadAndError();
        ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSeeText('The last request completed, but it did not produce a current AI review.')
            ->assertSeeText('Run AI review');
    }

    public function test_existing_ai_review_is_not_manually_queued_again(): void
    {
        Queue::fake();
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $this->review($payload, $parserError, $species);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.store', $parserError))
            ->assertSessionHas('status', 'An AI review is already available for this parser error.');

        Queue::assertNothingPushed();
        $this->assertSame(1, ParserDiagnosticReview::query()->count());
    }

    public function test_detached_matching_ai_review_is_reattached_without_queueing_again(): void
    {
        Queue::fake();
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);
        $review->forceFill(['parser_error_id' => null])->save();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.store', $parserError))
            ->assertSessionHas('status', 'An AI review is already available for this parser error.');

        Queue::assertNothingPushed();
        $this->assertSame($parserError->id, $review->refresh()->parser_error_id);
    }

    public function test_stale_attached_ai_review_does_not_block_manual_recovery(): void
    {
        Queue::fake();
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $this->review($payload, $parserError, $species);
        $parserError->forceFill([
            'diagnostic_fingerprint' => hash('sha256', 'replacement-diagnostic'),
        ])->save();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSeeText('No AI review is available.')
            ->assertSeeText('Run AI review')
            ->assertDontSeeText('Legitimate Alias');

        $this->post(route('admin.parser-errors.reviews.store', $parserError))
            ->assertSessionHas('status', 'AI review queued.');

        Queue::assertPushed(
            DispatchParserDiagnosticReviewBatchesJob::class,
            fn (DispatchParserDiagnosticReviewBatchesJob $job): bool => $job->rawScrapePayloadId === $payload->id,
        );
    }

    public function test_current_ai_review_is_used_when_a_newer_stale_review_is_also_attached(): void
    {
        Queue::fake();
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $currentReview = $this->review($payload, $parserError, $species, [
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $this->review($payload, $parserError, $species, [
            'diagnostic_fingerprint' => hash('sha256', 'stale-diagnostic'),
            'classification' => ParserDiagnosticReviewClassification::Uncertain,
            'validated_result' => [
                'classification' => ParserDiagnosticReviewClassification::Uncertain->value,
                'confidence' => 0.4,
                'rationale' => 'This review belongs to an older diagnostic.',
                'corrections' => [],
            ],
            'rationale' => 'This review belongs to an older diagnostic.',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSeeText('Legitimate Alias')
            ->assertSeeText($currentReview->rationale)
            ->assertDontSeeText('This review belongs to an older diagnostic.')
            ->assertDontSeeText('Run AI review');

        $this->post(route('admin.parser-errors.reviews.store', $parserError))
            ->assertSessionHas('status', 'An AI review is already available for this parser error.');

        Queue::assertNothingPushed();
    }

    public function test_manual_ai_review_requires_the_human_review_workflow(): void
    {
        Queue::fake();
        [, $parserError] = $this->payloadAndError();
        config()->set('fish.ai_review.human_review_enabled', false);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.store', $parserError))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_manual_ai_review_rejects_unavailable_dispatch_and_ineligible_errors(): void
    {
        Queue::fake();
        [, $parserError] = $this->payloadAndError();
        $admin = User::factory()->admin()->create();
        $route = route('admin.parser-errors.reviews.store', $parserError);

        config()->set('fish.ai_review.enabled', false);
        $this->actingAs($admin)->post($route)->assertSessionHasErrors('review');

        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', false);
        $this->post($route)->assertSessionHasErrors('review');

        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', null);
        $this->post($route)->assertSessionHasErrors('review');

        config()->set('services.openai.api_key', 'secret-provider-key');
        $parserError->forceFill([
            'resolved_at' => now(),
            'resolution_type' => ParserErrorResolutionType::Dismissed,
        ])->save();
        $this->post($route)->assertSessionHasErrors('review');

        $parserError->forceFill([
            'resolved_at' => null,
            'resolution_type' => null,
            'raw_scrape_payload_id' => null,
        ])->save();
        $this->post($route)->assertSessionHasErrors('review');

        Queue::assertNothingPushed();
    }

    public function test_only_admins_can_view_or_act_on_human_reviews(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);
        $user = User::factory()->create(['role' => Role::User]);

        $this->actingAs($user)->get(route('admin.parser-errors.index'))->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.parser-errors.reviews.store', $parserError))
            ->assertForbidden();

        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
    }

    public function test_mutating_review_forms_include_csrf_protection(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.parser-errors.index'))
            ->assertSee('name="_token"', false)
            ->assertSee(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSee(route('admin.parser-errors.reviews.reject', [$parserError, $review]))
            ->assertSee(route('admin.parser-errors.reviews.dismiss', [$parserError, $review]))
            ->assertSee(route('admin.parser-errors.reviews.retry', [$parserError, $review]))
            ->assertSee(route('admin.parser-errors.reviews.leave-open', [$parserError, $review]));

        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
    }

    public function test_missing_csrf_token_is_rejected_before_a_review_action_runs(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);
        $request = Request::create(route('admin.parser-errors.reviews.reject', [$parserError, $review]), 'POST');
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $middleware = new class(app(), app(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn () => response('Review action ran.'));
    }

    public function test_admin_can_accept_existing_species_alias_and_reparse_without_losing_the_diagnostic(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);
        $admin = User::factory()->admin()->create();
        $canonicalSpeciesCount = Species::query()->count();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'AI recommendation accepted. The payload was reparsed and deduplicated.');

        $this->assertDatabaseHas('species_aliases', [
            'species_id' => $species->id,
            'alias' => 'Moon Fish',
            'normalized_alias' => 'moon fish',
        ]);
        $this->assertSame($canonicalSpeciesCount, Species::query()->count());
        $this->assertModelExists($parserError);
        $this->assertSame(ParserErrorResolutionType::Alias, $parserError->refresh()->resolution_type);
        $this->assertSame($admin->id, $parserError->resolved_by_user_id);
        $this->assertDatabaseHas('parser_diagnostic_review_actions', [
            'parser_diagnostic_review_id' => $review->id,
            'parser_error_id' => $parserError->id,
            'actor_user_id' => $admin->id,
            'action' => ParserDiagnosticReviewActionType::Accepted->value,
        ]);
        $this->assertDatabaseHas('species_counts', ['species_id' => $species->id, 'count' => 4]);
    }

    public function test_duplicate_accept_click_cannot_repeat_the_mutation_or_audit(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);
        $admin = User::factory()->admin()->create();
        $route = route('admin.parser-errors.reviews.accept', [$parserError, $review]);

        $this->actingAs($admin)->post($route)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($route)->assertSessionHasErrors('review');

        $this->assertSame(1, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->count());
        $this->assertSame(1, $review->humanActions()->where('action', ParserDiagnosticReviewActionType::Accepted)->count());
    }

    public function test_competing_accept_and_dismiss_actions_have_one_serialized_resolution(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.dismiss', [$parserError, $review]))
            ->assertSessionHasErrors('review');

        $this->assertSame(ParserErrorResolutionType::Alias, $parserError->refresh()->resolution_type);
        $this->assertSame([ParserDiagnosticReviewActionType::Accepted], $review->humanActions()->pluck('action')->all());
    }

    public function test_stale_or_wrong_type_recommendations_cannot_mutate_data(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $inactiveSpecies = Species::query()->create(['name' => 'Inactive Opaleye', 'slug' => 'inactive-opaleye', 'is_active' => false]);
        $review = $this->review($payload, $parserError, $inactiveSpecies);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSessionHasErrors('review');

        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
        $this->assertNull($parserError->refresh()->resolved_at);

        $activeSpecies = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review->forceFill([
            'validated_result' => $this->validatedResult(CanonicalEntityType::Boat, $activeSpecies->id),
        ])->save();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSessionHasErrors('review');

        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
        $this->assertNull($parserError->refresh()->resolved_at);
    }

    public function test_fingerprint_mismatch_is_rejected_as_stale(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);
        $parserError->update(['diagnostic_fingerprint' => hash('sha256', 'changed-diagnostic')]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSessionHasErrors('review');

        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
        $this->assertNull($parserError->refresh()->resolved_at);
    }

    public function test_ui_and_acceptance_use_the_same_map_alias_target_when_corrections_are_mixed(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $replacementSpecies = Species::query()->create(['name' => 'Replacement Candidate', 'slug' => 'replacement-candidate']);
        $aliasSpecies = Species::query()->create(['name' => 'Alias Candidate', 'slug' => 'alias-candidate']);
        $review = $this->reviewForTarget($payload, $parserError, CanonicalEntityType::Species, $aliasSpecies->id, [
            'validated_result' => [
                ...$this->validatedResult(CanonicalEntityType::Species, $aliasSpecies->id),
                'corrections' => [
                    [
                        'operation' => 'replace_entity',
                        'report_index' => 0,
                        'field' => 'species',
                        'canonical_type' => CanonicalEntityType::Species->value,
                        'canonical_id' => $replacementSpecies->id,
                        'value' => null,
                        'retained_count' => null,
                        'released_count' => null,
                    ],
                    $this->validatedResult(CanonicalEntityType::Species, $aliasSpecies->id)['corrections'][0],
                ],
            ],
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertSeeText('Candidate: Alias Candidate')
            ->assertDontSeeText('Candidate: Replacement Candidate');
        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSessionHasNoErrors();

        $this->assertSame($aliasSpecies->id, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->value('species_id'));
    }

    public function test_admin_can_accept_existing_boat_alias_without_creating_a_boat(): void
    {
        [$payload, $parserError] = $this->payloadAndError([
            'error_type' => 'unknown_boat_alias',
            'raw_field' => 'boat',
            'raw_value' => 'The Dolphin',
        ]);
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $review = $this->reviewForTarget($payload, $parserError, CanonicalEntityType::Boat, $boat->id);
        $boatCount = Boat::query()->count();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSessionHasNoErrors();

        $this->assertSame($boatCount, Boat::query()->count());
        $this->assertDatabaseHas('boat_aliases', [
            'boat_id' => $boat->id,
            'normalized_alias' => 'the dolphin',
        ]);
        $this->assertSame(ParserErrorResolutionType::Alias, $parserError->refresh()->resolution_type);
    }

    public function test_admin_can_accept_existing_trip_type_alias_without_creating_a_trip_type(): void
    {
        [$payload, $parserError] = $this->payloadAndError([
            'error_type' => 'unknown_trip_type_alias',
            'raw_field' => 'trip_type',
            'raw_value' => 'All Day Special',
        ]);
        $tripType = TripType::query()->where('slug', 'full-day')->firstOrFail();
        $review = $this->reviewForTarget($payload, $parserError, CanonicalEntityType::TripType, $tripType->id);
        $tripTypeCount = TripType::query()->count();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]))
            ->assertSessionHasNoErrors();

        $this->assertSame($tripTypeCount, TripType::query()->count());
        $this->assertDatabaseHas('trip_type_aliases', [
            'trip_type_id' => $tripType->id,
            'normalized_alias' => 'all day special',
        ]);
        $this->assertSame(ParserErrorResolutionType::Alias, $parserError->refresh()->resolution_type);
    }

    public function test_accept_revalidates_alias_uniqueness_on_the_server(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $recommendedSpecies = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $existingSpecies = Species::query()->create(['name' => 'Moonfish', 'slug' => 'moonfish']);
        SpeciesAlias::query()->create([
            'species_id' => $existingSpecies->id,
            'alias' => 'Moon Fish',
            'normalized_alias' => 'moon fish',
        ]);
        $review = $this->review($payload, $parserError, $recommendedSpecies);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.accept', [$parserError, $review]), [
                'canonical_type' => CanonicalEntityType::Species->value,
                'canonical_id' => $existingSpecies->id,
                'confidence' => 1,
            ])
            ->assertSessionHasErrors('review');

        $this->assertSame($existingSpecies->id, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->value('species_id'));
        $this->assertNull($parserError->refresh()->resolved_at);
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
    }

    public function test_reject_and_leave_open_are_audited_without_resolving_the_error(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species, [
            'classification' => ParserDiagnosticReviewClassification::Uncertain,
            'validated_result' => [
                'classification' => ParserDiagnosticReviewClassification::Uncertain->value,
                'confidence' => 0.4,
                'rationale' => 'Needs human review.',
                'corrections' => [],
            ],
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.reject', [$parserError, $review]))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.leave-open', [$parserError, $review]))
            ->assertSessionHasNoErrors();

        $this->assertNull($parserError->refresh()->resolved_at);
        $this->assertEqualsCanonicalizing(
            [ParserDiagnosticReviewActionType::Rejected, ParserDiagnosticReviewActionType::LeftOpen],
            $review->humanActions()->pluck('action')->all(),
        );
        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertSeeText('This outcome stays open for human handling')
            ->assertDontSeeText('Accept existing alias');
    }

    public function test_dismiss_resolves_without_creating_any_canonical_entity_or_alias(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species);
        $admin = User::factory()->admin()->create();
        $speciesCount = Species::query()->count();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.dismiss', [$parserError, $review]))
            ->assertSessionHasNoErrors();

        $this->assertSame(ParserErrorResolutionType::Dismissed, $parserError->refresh()->resolution_type);
        $this->assertSame($speciesCount, Species::query()->count());
        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
        $this->assertDatabaseHas('parser_diagnostic_review_actions', [
            'parser_diagnostic_review_id' => $review->id,
            'action' => ParserDiagnosticReviewActionType::Dismissed->value,
        ]);
    }

    public function test_retry_preserves_audit_snapshot_resets_the_review_and_is_idempotent(): void
    {
        Queue::fake();
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species, [
            'status' => ParserDiagnosticReviewStatus::Stale,
        ]);
        $admin = User::factory()->admin()->create();
        $route = route('admin.parser-errors.reviews.retry', [$parserError, $review]);

        $this->actingAs($admin)->post($route)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($route)->assertSessionHasErrors('review');

        $review->refresh();
        $this->assertSame(ParserDiagnosticReviewStatus::Pending, $review->status);
        $this->assertNull($review->classification);
        $this->assertNull($review->validated_result);
        $this->assertSame(ParserDiagnosticReviewClassification::LegitimateAlias->value, data_get(
            $review->humanActions()->where('action', ParserDiagnosticReviewActionType::Retried)->sole()->details,
            'previous_result.classification',
        ));
        Queue::assertPushed(DispatchParserDiagnosticReviewBatchesJob::class, 1);
        Queue::assertPushed(DispatchParserDiagnosticReviewBatchesJob::class, fn (DispatchParserDiagnosticReviewBatchesJob $job): bool => $job->rawScrapePayloadId === $payload->id);
    }

    public function test_pending_review_cannot_be_rejected_as_a_completed_recommendation(): void
    {
        [$payload, $parserError] = $this->payloadAndError();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, $species, [
            'status' => ParserDiagnosticReviewStatus::Pending,
            'classification' => null,
            'validated_result' => null,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.parser-errors.reviews.reject', [$parserError, $review]))
            ->assertSessionHasErrors('review');

        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
        $this->assertNull($parserError->refresh()->resolved_at);
    }

    /** @return array{RawScrapePayload, ParserError} */
    private function payloadAndError(array $overrides = []): array
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-12',
        ]);
        $body = '<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>';
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-12',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php?date=2026-07-12',
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => now(),
        ]);
        $parserError = ParserError::query()->create(array_merge([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => $payload->target_date,
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Moon Fish',
            'message' => 'Unknown species alias.',
            'context' => ['sanitized_paragraph' => '4 Moon Fish.', 'parser_version' => 'parser-v1'],
            'report_fingerprint' => hash('sha256', 'report'),
            'diagnostic_fingerprint' => hash('sha256', 'diagnostic'),
        ], $overrides));

        return [$payload, $parserError];
    }

    private function review(
        RawScrapePayload $payload,
        ParserError $parserError,
        Species $species,
        array $overrides = [],
    ): ParserDiagnosticReview {
        return $this->reviewForTarget(
            $payload,
            $parserError,
            CanonicalEntityType::Species,
            $species->id,
            $overrides,
        );
    }

    private function reviewForTarget(
        RawScrapePayload $payload,
        ParserError $parserError,
        CanonicalEntityType $canonicalType,
        int $canonicalId,
        array $overrides = [],
    ): ParserDiagnosticReview {
        $validatedResult = $this->validatedResult($canonicalType, $canonicalId);

        return ParserDiagnosticReview::query()->create(array_merge([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'status' => ParserDiagnosticReviewStatus::Succeeded,
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v5',
            'schema_version' => 'v2',
            'classification' => ParserDiagnosticReviewClassification::LegitimateAlias,
            'confidence' => 0.98,
            'validated_result' => $validatedResult,
            'rationale' => 'The source value is an existing canonical alias.',
            'input_tokens' => 100,
            'cached_input_tokens' => 25,
            'output_tokens' => 50,
            'reasoning_tokens' => 10,
            'total_tokens' => 150,
            'estimated_cost_micros' => 125000,
            'completed_at' => now(),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function validatedResult(CanonicalEntityType $canonicalType, int $canonicalId): array
    {
        $field = match ($canonicalType) {
            CanonicalEntityType::Boat => 'boat',
            CanonicalEntityType::Species => 'species',
            CanonicalEntityType::TripType => 'trip_type',
        };

        return [
            'classification' => ParserDiagnosticReviewClassification::LegitimateAlias->value,
            'confidence' => 0.98,
            'rationale' => 'The source value is an existing canonical alias.',
            'corrections' => [[
                'operation' => 'map_alias',
                'report_index' => 0,
                'field' => $field,
                'canonical_type' => $canonicalType->value,
                'canonical_id' => $canonicalId,
                'value' => null,
                'retained_count' => null,
                'released_count' => null,
            ]],
        ];
    }
}
