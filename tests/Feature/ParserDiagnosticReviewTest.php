<?php

namespace Tests\Feature;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\DTOs\CanonicalCandidateData;
use App\DTOs\ParserDiagnosticReviewRequestData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserDiagnosticType;
use App\Enums\ScrapeRunType;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Services\AI\DisabledParserDiagnosticReviewer;
use App\Services\AI\ParserDiagnosticReviewRequestFactory;
use App\Services\AI\ParserDiagnosticReviewRequestValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class ParserDiagnosticReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_schema_model_defaults_casts_relationships_and_guarding(): void
    {
        $payload = $this->payload();
        $parserError = $this->parserError($payload);
        $review = ParserDiagnosticReview::query()->create([
            'id' => 999,
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => hash('sha256', 'diagnostic'),
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'classification' => ParserDiagnosticReviewClassification::LegitimateAlias,
            'confidence' => 0.925,
            'validated_result' => ['classification' => 'legitimate_alias'],
        ]);

        $this->assertNotSame(999, $review->id);
        $this->assertSame(ParserDiagnosticReviewStatus::Pending, $review->status);
        $this->assertSame('openai', $review->provider);
        $this->assertSame(0, $review->cache_write_tokens);
        $this->assertSame(0, $review->attempts);
        $this->assertSame('0.9250', $review->confidence);
        $this->assertSame(['classification' => 'legitimate_alias'], $review->validated_result);
        $this->assertTrue($review->rawScrapePayload->is($payload));
        $this->assertTrue($review->parserError->is($parserError));
        $this->assertTrue($payload->parserDiagnosticReviews->contains($review));
        $this->assertTrue($parserError->diagnosticReviews->contains($review));
        $this->assertTrue(Schema::hasColumns('parser_diagnostic_reviews', [
            'raw_scrape_payload_id',
            'payload_hash',
            'parser_error_id',
            'diagnostic_fingerprint',
            'validated_result',
            'estimated_cost_micros',
            'service_tier',
            'cache_write_tokens',
            'cost_calculation_version',
            'pricing_snapshot',
            'failure_message',
        ]));
    }

    public function test_review_survives_parser_error_replacement(): void
    {
        $payload = $this->payload();
        $parserError = $this->parserError($payload);
        $review = $this->review($payload, $parserError);

        $parserError->delete();

        $this->assertModelExists($review);
        $this->assertNull($review->refresh()->parser_error_id);
        $this->assertTrue($review->rawScrapePayload->is($payload));
    }

    public function test_payload_and_diagnostic_fingerprint_are_unique(): void
    {
        $payload = $this->payload();
        $this->review($payload);

        $this->expectException(QueryException::class);

        $this->review($payload);
    }

    public function test_every_review_state_transition_is_explicit(): void
    {
        $legalTransitions = [
            'pending:running',
            'pending:stale',
            'pending:skipped',
            'running:succeeded',
            'running:failed',
            'running:refused',
            'running:stale',
            'failed:pending',
            'succeeded:pending',
            'refused:pending',
            'stale:pending',
            'skipped:pending',
        ];

        foreach (ParserDiagnosticReviewStatus::cases() as $from) {
            foreach (ParserDiagnosticReviewStatus::cases() as $to) {
                $isLegal = in_array("{$from->value}:{$to->value}", $legalTransitions, true);

                $this->assertSame($isLegal, $from->canTransitionTo($to), "Unexpected {$from->value} to {$to->value} transition.");
            }
        }
    }

    public function test_model_transition_tracks_attempts_and_rejects_illegal_changes(): void
    {
        $review = $this->review($this->payload());

        $review->transitionTo(ParserDiagnosticReviewStatus::Running);

        $this->assertSame(ParserDiagnosticReviewStatus::Running, $review->status);
        $this->assertSame(1, $review->attempts);
        $this->assertNotNull($review->started_at);

        $review->transitionTo(ParserDiagnosticReviewStatus::Succeeded);

        $this->assertNotNull($review->completed_at);
        $review->transitionTo(ParserDiagnosticReviewStatus::Pending);
        $this->assertSame(ParserDiagnosticReviewStatus::Pending, $review->status);
    }

    public function test_failure_metadata_is_bounded_before_persistence(): void
    {
        config()->set('fish.ai_review.limits.max_failure_message_length', 20);
        $review = $this->review($this->payload());
        $review->transitionTo(ParserDiagnosticReviewStatus::Running);

        $review->fail(Str::repeat('x', 100));

        $this->assertSame(ParserDiagnosticReviewStatus::Failed, $review->status);
        $this->assertSame(20, Str::length($review->failure_message));
        $this->assertNotNull($review->failed_at);
    }

    public function test_request_validator_accepts_only_active_relevant_canonical_candidates(): void
    {
        $payload = $this->payload();
        $species = Species::query()->where('is_active', true)->firstOrFail();
        $request = new ParserDiagnosticReviewRequestData(
            payloadId: $payload->id,
            payloadHash: $payload->payload_hash,
            diagnosticFingerprint: hash('sha256', 'diagnostic'),
            diagnosticType: ParserDiagnosticType::UnknownAlias,
            field: 'species',
            rawValue: 'Moon Fish',
            context: ['sanitized_paragraph' => 'A public fish-count paragraph.'],
            candidates: [new CanonicalCandidateData(CanonicalEntityType::Species, $species->id, $species->name)],
        );

        app(ParserDiagnosticReviewRequestValidator::class)->validate($request);
        $this->addToAssertionCount(1);

        $species->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(ParserDiagnosticReviewRequestValidator::class)->validate($request);
    }

    public function test_report_level_numeric_diagnostics_receive_active_species_candidates(): void
    {
        $payload = $this->payload();
        $parserError = $this->parserError($payload);
        $parserError->forceFill([
            'error_type' => 'unaccounted_numeric_tokens',
            'raw_field' => 'report',
            'raw_value' => '142, 72',
            'context' => [
                'sanitized_paragraph' => 'The Pacific Queen returned with 142 Bluefin Tuna, including 72 large fish.',
                'evidence' => ['unaccounted_tokens' => ['142', '72']],
            ],
        ])->save();

        $request = app(ParserDiagnosticReviewRequestFactory::class)->make($payload, $parserError);

        $this->assertNotEmpty($request->candidates);
        $this->assertSame(
            [CanonicalEntityType::Species],
            collect($request->candidates)->pluck('type')->unique()->values()->all(),
        );
        $this->assertTrue(
            collect($request->candidates)->contains(
                fn (CanonicalCandidateData $candidate): bool => $candidate->name === 'Bluefin Tuna',
            ),
        );
        app(ParserDiagnosticReviewRequestValidator::class)->validate($request);
        $this->addToAssertionCount(1);
    }

    public function test_request_validator_rejects_a_payload_hash_from_another_payload(): void
    {
        $payload = $this->payload();
        $species = Species::query()->where('is_active', true)->firstOrFail();
        $request = new ParserDiagnosticReviewRequestData(
            payloadId: $payload->id,
            payloadHash: hash('sha256', 'different-payload'),
            diagnosticFingerprint: hash('sha256', 'diagnostic'),
            diagnosticType: ParserDiagnosticType::UnknownAlias,
            field: 'species',
            rawValue: 'Moon Fish',
            context: [],
            candidates: [new CanonicalCandidateData(CanonicalEntityType::Species, $species->id, $species->name)],
        );

        $this->expectException(ValidationException::class);
        app(ParserDiagnosticReviewRequestValidator::class)->validate($request);
    }

    public function test_request_validator_rejects_a_field_incompatible_with_the_diagnostic_type(): void
    {
        $payload = $this->payload();
        $request = new ParserDiagnosticReviewRequestData(
            payloadId: $payload->id,
            payloadHash: $payload->payload_hash,
            diagnosticFingerprint: hash('sha256', 'diagnostic'),
            diagnosticType: ParserDiagnosticType::FractionalTripConflict,
            field: 'anglers',
            rawValue: '22',
            context: [],
            candidates: [],
        );

        $this->expectException(ValidationException::class);
        app(ParserDiagnosticReviewRequestValidator::class)->validate($request);
    }

    public function test_phase_three_migrations_reverse_and_reapply_cleanly(): void
    {
        $periodMigration = require database_path('migrations/2026_07_12_224940_create_ai_budget_periods_table.php');
        $reviewMigration = require database_path('migrations/2026_07_12_224941_create_parser_diagnostic_reviews_table.php');
        $reservationMigration = require database_path('migrations/2026_07_12_224942_create_ai_budget_reservations_table.php');

        $reservationMigration->down();
        $reviewMigration->down();
        $periodMigration->down();

        $this->assertFalse(Schema::hasTable('ai_budget_periods'));
        $this->assertFalse(Schema::hasTable('parser_diagnostic_reviews'));
        $this->assertFalse(Schema::hasTable('ai_budget_reservations'));

        $periodMigration->up();
        $reviewMigration->up();
        $reservationMigration->up();

        $this->assertTrue(Schema::hasTable('ai_budget_periods'));
        $this->assertTrue(Schema::hasTable('parser_diagnostic_reviews'));
        $this->assertTrue(Schema::hasTable('ai_budget_reservations'));
    }

    public function test_reviewer_contract_is_bound_to_a_disabled_no_network_implementation(): void
    {
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        $reviewer = app(ParserDiagnosticReviewer::class);

        $this->assertInstanceOf(DisabledParserDiagnosticReviewer::class, $reviewer);
        $this->expectException(LogicException::class);

        $reviewer->review([new ParserDiagnosticReviewRequestData(
            payloadId: 1,
            payloadHash: hash('sha256', 'payload'),
            diagnosticFingerprint: hash('sha256', 'diagnostic'),
            diagnosticType: ParserDiagnosticType::UnknownAlias,
            field: 'species',
            rawValue: 'Moon Fish',
            context: [],
            candidates: [],
        )]);
    }

    private function review(RawScrapePayload $payload, ?ParserError $parserError = null): ParserDiagnosticReview
    {
        return ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError?->id,
            'diagnostic_fingerprint' => hash('sha256', 'same-diagnostic'),
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
        ]);
    }

    private function parserError(RawScrapePayload $payload): ParserError
    {
        return ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Moon Fish',
            'message' => 'Unknown species alias.',
            'diagnostic_fingerprint' => hash('sha256', 'parser-error'),
        ]);
    }

    private function payload(): RawScrapePayload
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-12',
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-12',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<p>Fish count.</p>',
            'payload_hash' => hash('sha256', 'payload'),
            'fetched_at' => now(),
        ]);
    }
}
