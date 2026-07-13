<?php

namespace Tests\Feature;

use App\Actions\Parsing\ApproveParserReportOverride;
use App\Actions\Parsing\DisableParserReportOverride;
use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\RawPayloadData;
use App\Enums\ParserBugReportStatus;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserReportOverrideStatus;
use App\Enums\ScrapeRunType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserBugReport;
use App\Models\ParserBugReportOccurrence;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\ParserReportOverride;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use App\Services\IssueTracking\ParserBugIssueCandidateFactory;
use App\Services\Parsing\ParserReportOverrideApplier;
use App\Services\Parsing\ParserReportOverrideValidator;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class ParserReportOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fish.parsing.overrides.enabled', true);
        config()->set('fish.parsing.overrides.allowed_source_slugs', ['fishermans_landing']);
        config()->set('fish.ai_review.human_review_enabled', true);
        config()->set('fish.github_issues.enabled', true);
        Queue::fake();
    }

    public function test_validator_accepts_only_the_approved_typed_fields_and_active_canonical_targets(): void
    {
        $this->seed(DatabaseSeeder::class);
        $validator = app(ParserReportOverrideValidator::class);
        $landing = Landing::query()->firstOrFail();
        $boat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Test Boat',
            'slug' => 'test-boat',
        ]);
        $tripType = TripType::query()->where('is_active', true)->firstOrFail();
        $species = Species::query()->where('is_active', true)->firstOrFail();
        $corrections = [
            $this->correction('replace_entity', 'boat', 'boat', $boat->id),
            $this->correction('replace_entity', 'trip_type', 'trip_type', $tripType->id),
            $this->correction('set_angler_count', 'anglers', value: 22),
            $this->correction('replace_entity', 'species', 'species', $species->id, matchValue: 'Yellow Tail'),
            $this->correction('set_species_count', 'species_count', 'species', $species->id, retained: 12, released: 3),
        ];

        $validated = $validator->validate($corrections, 0);

        $this->assertCount(5, $validated);
        $this->assertSame(22, $validated[2]->value);
        $this->assertSame(12, $validated[4]->retainedCount);
        $this->assertSame(3, $validated[4]->releasedCount);
    }

    public function test_validator_rejects_arbitrary_fields_operations_content_counts_and_invalid_targets(): void
    {
        $this->seed(DatabaseSeeder::class);
        $validator = app(ParserReportOverrideValidator::class);
        $species = Species::query()->where('is_active', true)->firstOrFail();
        $inactiveSpecies = Species::query()->create([
            'name' => 'Inactive Fish',
            'slug' => 'inactive-fish',
            'is_active' => false,
        ]);
        $tripType = TripType::query()->where('is_active', true)->firstOrFail();
        $inactiveTripType = TripType::query()->create([
            'name' => 'Inactive Trip',
            'slug' => 'inactive-trip',
            'is_active' => false,
        ]);
        $landing = Landing::query()->firstOrFail();
        $inactiveBoat = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Inactive Boat',
            'slug' => 'inactive-boat',
            'is_active' => false,
        ]);

        $this->assertValidationFails(fn () => $validator->validate([
            array_merge($this->correction('set_angler_count', 'anglers', value: 10), ['sql' => 'DROP TABLE trip_reports']),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('replace_entity', 'landing', 'boat', 1),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('remove_species_count', 'species_count', 'species', $species->id),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('set_angler_count', 'anglers', value: -1),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('set_species_count', 'species_count', 'species', $species->id, retained: 1_000_001, released: 0),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('replace_entity', 'species', 'species', $inactiveSpecies->id, matchValue: 'Fish'),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('replace_entity', 'boat', 'boat', $inactiveBoat->id),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('replace_entity', 'trip_type', 'trip_type', $inactiveTripType->id),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('replace_entity', 'species', 'trip_type', $tripType->id, matchValue: 'Fish'),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('replace_entity', 'species', 'species', 999999, matchValue: 'Fish'),
        ], 0));
        $this->assertValidationFails(fn () => $validator->validate([
            $this->correction('replace_entity', 'species', 'species', $species->id, matchValue: 'https://evil.test/payload'),
        ], 0));
    }

    public function test_override_requires_admin_manual_approval_and_a_published_deduplicated_issue(): void
    {
        [$payload, $parserError, $review] = $this->scenario(issuePublished: false);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertSessionHasErrors('override');

        $this->assertDatabaseCount('parser_report_overrides', 0);
        $this->assertSame(40, $this->retainedCount($payload));

        $review->parserBugReport()->firstOrFail()->update([
            'status' => ParserBugReportStatus::Open,
            'issue_number' => 84,
            'issue_url' => 'https://github.com/giantsoup/fishcounts/issues/84',
            'issue_state' => 'open',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $override = ParserReportOverride::query()->sole();
        $this->assertSame(ParserReportOverrideStatus::Pending, $override->status);
        $this->assertSame($admin->id, $override->created_by_user_id);
        $this->assertSame(40, data_get($override->original_parse, 'species_counts.0.retained'));
        $this->assertSame(25, data_get($override->corrected_parse, 'species_counts.0.retained'));
        $this->assertSame(40, $this->retainedCount($payload), 'A pending proposal must not patch normalized rows.');

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSee('Report-scoped override')
            ->assertSee('Original deterministic parse')
            ->assertSee("payload #{$payload->id}")
            ->assertSee('7/1/2026');
    }

    public function test_approval_applies_before_normalization_is_idempotent_and_disable_restores_history(): void
    {
        [$payload, $parserError, $review] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]));
        $override = ParserReportOverride::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.parser-report-overrides.approve', $override), ['review_notes' => 'Verified against source.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $override->refresh();
        $this->assertSame(ParserReportOverrideStatus::Active, $override->status);
        $this->assertSame($admin->id, $override->approved_by_user_id);
        $this->assertSame('Verified against source.', $override->review_notes);
        $this->assertNotNull($override->first_applied_at);
        $this->assertSame(25, $this->retainedCount($payload));
        $this->assertSame(1, TripReport::query()->where('raw_scrape_payload_id', $payload->id)->count());
        $this->assertTrue(TripReport::query()->where('raw_scrape_payload_id', $payload->id)->sole()->is_deduped_primary);

        $this->actingAs($admin)
            ->get(route('admin.raw-payloads.show', $payload))
            ->assertOk()
            ->assertSee('Report override audit history')
            ->assertSee('Disable override and restore deterministic parse');

        app(ParseRawPayloadAction::class)->handle($payload->id, false);
        $this->assertSame(25, $this->retainedCount($payload));
        $this->assertSame(1, TripReport::query()->where('raw_scrape_payload_id', $payload->id)->count());
        $this->assertSame(1, ParserReportOverride::query()->count());

        $this->actingAs($admin)
            ->post(route('admin.parser-report-overrides.disable', $override), ['disable_reason' => 'Rollback verification.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $override->refresh();
        $this->assertSame(ParserReportOverrideStatus::Disabled, $override->status);
        $this->assertSame($admin->id, $override->disabled_by_user_id);
        $this->assertSame(40, $this->retainedCount($payload));
        $this->assertSame(1, TripReport::query()->where('raw_scrape_payload_id', $payload->id)->count());

        $disabledAt = $override->disabled_at;
        $this->actingAs($admin)
            ->post(route('admin.parser-report-overrides.approve', $override))
            ->assertSessionHasErrors('override');
        $this->assertSame(ParserReportOverrideStatus::Disabled, $override->refresh()->status);
        $this->assertTrue($disabledAt->equalTo($override->disabled_at));
        $this->assertSame('Rollback verification.', $override->disable_reason);
    }

    public function test_all_allowed_fields_apply_with_exact_canonical_ids_before_normalization(): void
    {
        [$payload, $parserError, $review] = $this->scenario();
        $sourceLanding = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        $targetBoat = Boat::query()->create([
            'landing_id' => $sourceLanding->id,
            'name' => 'Dolphin II',
            'slug' => 'dolphin-ii',
        ]);
        $targetTripType = TripType::query()->where('name', '1/2 Day')->firstOrFail();
        $targetSpecies = Species::query()->where('name', 'Bluefin Tuna')->firstOrFail();
        $parserError->update(['raw_value' => 'Yellowtail']);
        $review->update([
            'validated_result' => [
                'classification' => 'value_extraction_error',
                'confidence' => 0.99,
                'rationale' => 'Correct every report field.',
                'corrections' => [
                    $this->correction('replace_entity', 'boat', 'boat', $targetBoat->id),
                    $this->correction('replace_entity', 'trip_type', 'trip_type', $targetTripType->id),
                    $this->correction('set_angler_count', 'anglers', value: 12),
                    $this->correction('replace_entity', 'species', 'species', $targetSpecies->id),
                    $this->correction('set_species_count', 'species_count', 'species', $targetSpecies->id, retained: 7, released: 3),
                ],
            ],
        ]);
        $candidate = app(ParserBugIssueCandidateFactory::class)->make($review->refresh());
        $review->parserBugReport()->firstOrFail()->update([
            'signature' => $candidate->signature,
            'source_slug' => $candidate->sourceSlug,
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $override = ParserReportOverride::query()->sole();
        $this->actingAs($admin)
            ->post(route('admin.parser-report-overrides.approve', $override))
            ->assertSessionHasNoErrors();

        $tripReport = TripReport::query()->where('raw_scrape_payload_id', $payload->id)->sole();
        $speciesCount = $tripReport->speciesCounts()->sole();
        $this->assertSame($targetBoat->id, $tripReport->boat_id);
        $this->assertSame($targetTripType->id, $tripReport->trip_type_id);
        $this->assertSame(12, $tripReport->anglers);
        $this->assertSame($targetSpecies->id, $speciesCount->species_id);
        $this->assertSame(7, $speciesCount->count);
        $this->assertSame(3, $speciesCount->released_count);
    }

    public function test_stale_payload_disallowed_source_and_invalid_issue_linkage_are_rejected(): void
    {
        [$payload, $parserError, $review] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $payload->update(['payload_hash' => hash('sha256', 'changed-after-review')]);

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertSessionHasErrors('override');

        $payload->update(['payload_hash' => $review->payload_hash]);
        config()->set('fish.parsing.overrides.allowed_source_slugs', []);
        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertSessionHasErrors('override');

        config()->set('fish.parsing.overrides.allowed_source_slugs', ['fishermans_landing']);
        $bugReport = $review->parserBugReport()->firstOrFail();
        $bugReport->update(['issue_url' => 'https://github.com/another/repository/issues/83']);
        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertSessionHasErrors('override');

        $bugReport->update([
            'issue_url' => 'https://github.com/giantsoup/fishcounts/issues/83',
            'signature' => hash('sha256', 'wrong-signature'),
        ]);
        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertSessionHasErrors('override');

        $candidate = app(ParserBugIssueCandidateFactory::class)->make($review);
        $bugReport->update(['signature' => $candidate->signature]);
        $bugReport->occurrences()->update(['invalidated_at' => now()]);
        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]))
            ->assertSessionHasErrors('override');

        $this->assertDatabaseCount('parser_report_overrides', 0);
    }

    public function test_stale_review_attempt_invalidates_pending_override_and_fresh_attempt_can_be_proposed(): void
    {
        [$payload, $parserError, $review] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]));
        $staleOverride = ParserReportOverride::query()->sole();
        $review->increment('attempts');
        ParserBugReportOccurrence::query()->create([
            'parser_bug_report_id' => $review->parser_bug_report_id,
            'parser_diagnostic_review_id' => $review->id,
            'parser_error_id' => $parserError->id,
            'review_attempt' => $review->attempts,
            'seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.parser-report-overrides.approve', $staleOverride))
            ->assertSessionHasErrors('override');

        $this->assertSame(ParserReportOverrideStatus::Invalidated, $staleOverride->refresh()->status);
        $this->assertSame(40, $this->retainedCount($payload));

        $this->actingAs($admin)
            ->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review->refresh()]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ParserReportOverride::query()->count());
        $freshOverride = ParserReportOverride::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $freshOverride->review_attempt);
        $this->assertSame(ParserReportOverrideStatus::Pending, $freshOverride->status);
    }

    public function test_approval_and_disable_roll_back_state_when_reparse_fails(): void
    {
        [$payload, $parserError, $review] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]));
        $override = ParserReportOverride::query()->sole();
        $this->mock(ParseRawPayloadAction::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('Parse failed.'));
        });

        try {
            app(ApproveParserReportOverride::class)->handle($override, $admin);
            $this->fail('Expected approval reparse to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Parse failed.', $exception->getMessage());
        }

        $this->assertSame(ParserReportOverrideStatus::Pending, $override->refresh()->status);
        $this->assertSame(40, $this->retainedCount($payload));

        $this->forgetMock(ParseRawPayloadAction::class);
        app(ApproveParserReportOverride::class)->handle($override, $admin);
        $this->assertSame(25, $this->retainedCount($payload));
        $this->mock(ParseRawPayloadAction::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('Rollback parse failed.'));
        });

        try {
            app(DisableParserReportOverride::class)->handle($override, $admin, 'Test failure.');
            $this->fail('Expected disable reparse to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback parse failed.', $exception->getMessage());
        }

        $this->assertSame(ParserReportOverrideStatus::Active, $override->refresh()->status);
        $this->assertSame(25, $this->retainedCount($payload));
    }

    public function test_recursion_guard_returns_the_deterministic_dto_without_reapplying(): void
    {
        [$payload] = $this->scenario();
        $payload->load('scrapeSource');
        $rawPayload = new RawPayloadData(
            sourceKey: $payload->scrapeSource->slug,
            targetDate: CarbonImmutable::parse($payload->target_date),
            url: $payload->url,
            body: $payload->payload,
            metadata: $payload->metadata ?? [],
        );
        $parsed = app(SourceAdapterRegistry::class)->forSource($payload->scrapeSource)->parse($rawPayload);
        $applier = app(ParserReportOverrideApplier::class);
        $guard = new ReflectionProperty($applier, 'isApplying');
        $guard->setValue($applier, true);

        $result = $applier->apply($payload, $rawPayload, $parsed);

        $this->assertSame($parsed, $result);
        $this->assertSame(40, $result->tripReports->first()->speciesCounts[0]->count);
    }

    public function test_kill_switch_prevents_application_without_changing_the_audit_record(): void
    {
        [$payload, $parserError, $review] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]));
        $override = ParserReportOverride::query()->sole();
        $this->actingAs($admin)->post(route('admin.parser-report-overrides.approve', $override));
        $this->assertSame(25, $this->retainedCount($payload));

        config()->set('fish.parsing.overrides.allowed_source_slugs', []);
        app(ParseRawPayloadAction::class)->handle($payload->id, false);
        $this->assertSame(40, $this->retainedCount($payload));
        $this->assertSame(ParserReportOverrideStatus::Active, $override->refresh()->status);

        config()->set('fish.parsing.overrides.allowed_source_slugs', ['fishermans_landing']);
        app(ParseRawPayloadAction::class)->handle($payload->id, false);
        $this->assertSame(25, $this->retainedCount($payload));

        config()->set('fish.parsing.overrides.enabled', false);
        app(ParseRawPayloadAction::class)->handle($payload->id, false);

        $this->assertSame(40, $this->retainedCount($payload));
        $this->assertSame(ParserReportOverrideStatus::Active, $override->refresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.parser-report-overrides.disable', $override), ['disable_reason' => 'Global rollback.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(ParserReportOverrideStatus::Disabled, $override->refresh()->status);
    }

    public function test_schema_and_source_fingerprint_changes_invalidate_and_restore_deterministic_data(): void
    {
        [$payload, $parserError, $review] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$parserError, $review]));
        $override = ParserReportOverride::query()->sole();
        $this->actingAs($admin)->post(route('admin.parser-report-overrides.approve', $override));

        config()->set('fish.parsing.overrides.schema_version', 'v2');
        app(ParseRawPayloadAction::class)->handle($payload->id, false);

        $this->assertSame(ParserReportOverrideStatus::Invalidated, $override->refresh()->status);
        $this->assertSame('correction_schema_changed', $override->invalidation_reason);
        $this->assertSame(40, $this->retainedCount($payload));

        config()->set('fish.parsing.overrides.schema_version', 'v1');
        [$otherPayload, $otherError, $otherReview] = $this->scenario('2026-07-02');
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$otherError, $otherReview]));
        $otherOverride = ParserReportOverride::query()->whereBelongsTo($otherPayload)->sole();
        $this->actingAs($admin)->post(route('admin.parser-report-overrides.approve', $otherOverride));
        $otherPayload->update([
            'payload' => '<p>Dolphin Full Day 20 anglers 41 Yellowtail</p>',
            'payload_hash' => hash('sha256', 'changed-source'),
        ]);

        app(ParseRawPayloadAction::class)->handle($otherPayload->id, false);

        $this->assertSame(ParserReportOverrideStatus::Invalidated, $otherOverride->refresh()->status);
        $this->assertContains($otherOverride->invalidation_reason, ['source_paragraph_changed', 'report_fingerprint_changed']);
        $this->assertSame(41, $this->retainedCount($otherPayload));

        [$versionPayload, $versionError, $versionReview] = $this->scenario('2026-07-03');
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$versionError, $versionReview]));
        $versionOverride = ParserReportOverride::query()->whereBelongsTo($versionPayload)->sole();
        $this->actingAs($admin)->post(route('admin.parser-report-overrides.approve', $versionOverride));
        $versionOverride->update(['parser_version' => 'retired-parser-v1']);

        app(ParseRawPayloadAction::class)->handle($versionPayload->id, false);

        $this->assertSame(ParserReportOverrideStatus::Invalidated, $versionOverride->refresh()->status);
        $this->assertSame('parser_version_changed', $versionOverride->invalidation_reason);
        $this->assertSame(40, $this->retainedCount($versionPayload));

        [$fingerprintPayload, $fingerprintError, $fingerprintReview] = $this->scenario('2026-07-04');
        $this->actingAs($admin)->post(route('admin.parser-errors.reviews.report-overrides.store', [$fingerprintError, $fingerprintReview]));
        $fingerprintOverride = ParserReportOverride::query()->whereBelongsTo($fingerprintPayload)->sole();
        $this->actingAs($admin)->post(route('admin.parser-report-overrides.approve', $fingerprintOverride));
        $fingerprintPayload->update(['payload_hash' => hash('sha256', 'same-paragraph-new-payload-fingerprint')]);

        app(ParseRawPayloadAction::class)->handle($fingerprintPayload->id, false);

        $this->assertSame(ParserReportOverrideStatus::Invalidated, $fingerprintOverride->refresh()->status);
        $this->assertSame('report_fingerprint_changed', $fingerprintOverride->invalidation_reason);
        $this->assertSame(40, $this->retainedCount($fingerprintPayload));
    }

    /** @return array{RawScrapePayload, ParserError, ParserDiagnosticReview} */
    private function scenario(string $targetDate = '2026-07-01', bool $issuePublished = true): array
    {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        Boat::query()->firstOrCreate(['landing_id' => $landing->id, 'slug' => 'dolphin'], ['name' => 'Dolphin']);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => $targetDate,
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $targetDate,
            'url' => "https://www.fishermanslanding.com/fishcounts.php?date={$targetDate}",
            'payload' => '<p>Dolphin Full Day 20 anglers 40 Yellowtail</p>',
            'payload_hash' => hash('sha256', "override-fixture-{$targetDate}"),
            'fetched_at' => now(),
        ]);
        app(ParseRawPayloadAction::class)->handle($payload->id, false);
        $species = Species::query()->where('name', 'Yellowtail')->firstOrFail();
        $diagnosticFingerprint = hash('sha256', "override-diagnostic-{$targetDate}");
        $parserError = ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => $targetDate,
            'error_type' => 'extracted_value_source_span_mismatch',
            'raw_field' => 'species_counts',
            'raw_value' => '40 Yellowtail',
            'message' => 'The retained count was parsed incorrectly.',
            'context' => [
                'source' => $source->slug,
                'url' => $payload->url,
                'report_index' => 0,
                'parser_version' => 'source-specific-fishermans_landing-v2',
                'sanitized_paragraph' => 'Dolphin Full Day 20 anglers 40 Yellowtail',
                'extracted_fields' => [
                    'boat' => 'Dolphin',
                    'trip_type' => 'Full Day',
                    'anglers' => 20,
                    'species_counts' => [['species' => 'Yellowtail', 'retained' => 40, 'released' => 0]],
                ],
                'evidence' => ['source_span' => '40 Yellowtail'],
            ],
            'report_fingerprint' => hash('sha256', "legacy-report-{$targetDate}"),
            'diagnostic_fingerprint' => $diagnosticFingerprint,
        ]);
        $review = ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $diagnosticFingerprint,
            'payload_hash' => $payload->payload_hash,
            'status' => ParserDiagnosticReviewStatus::Succeeded,
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'classification' => ParserDiagnosticReviewClassification::ValueExtractionError,
            'confidence' => 0.99,
            'validated_result' => [
                'classification' => 'value_extraction_error',
                'confidence' => 0.99,
                'rationale' => 'The source shows 25 retained fish.',
                'corrections' => [$this->correction(
                    'set_species_count',
                    'species_count',
                    'species',
                    $species->id,
                    retained: 25,
                    released: 0,
                )],
            ],
            'completed_at' => now(),
        ]);
        $candidate = app(ParserBugIssueCandidateFactory::class)->make($review);
        $bugReport = ParserBugReport::query()->where('signature', $candidate->signature)->first()
            ?? ParserBugReport::factory()->create([
                'parser_diagnostic_review_id' => $review->id,
                'review_attempt' => $review->attempts,
                'signature' => $candidate->signature,
                'source_slug' => $candidate->sourceSlug,
                'status' => $issuePublished ? ParserBugReportStatus::Open : ParserBugReportStatus::Preview,
                'issue_number' => $issuePublished ? 83 : null,
                'issue_url' => $issuePublished ? 'https://github.com/giantsoup/fishcounts/issues/83' : null,
                'issue_state' => $issuePublished ? 'open' : null,
            ]);
        $review->update(['parser_bug_report_id' => $bugReport->id]);
        ParserBugReportOccurrence::query()->create([
            'parser_bug_report_id' => $bugReport->id,
            'parser_diagnostic_review_id' => $review->id,
            'parser_error_id' => $parserError->id,
            'review_attempt' => $review->attempts,
            'seen_at' => now(),
        ]);

        return [$payload, $parserError, $review->refresh()];
    }

    /** @return array<string, int|string|null> */
    private function correction(
        string $operation,
        string $field,
        ?string $canonicalType = null,
        ?int $canonicalId = null,
        ?int $value = null,
        ?int $retained = null,
        ?int $released = null,
        ?string $matchValue = null,
    ): array {
        return [
            'operation' => $operation,
            'report_index' => 0,
            'field' => $field,
            'canonical_type' => $canonicalType,
            'canonical_id' => $canonicalId,
            'value' => $value,
            'retained_count' => $retained,
            'released_count' => $released,
            'match_value' => $matchValue,
        ];
    }

    private function retainedCount(RawScrapePayload $payload): int
    {
        $tripReportIds = TripReport::query()->where('raw_scrape_payload_id', $payload->id)->pluck('id');

        return (int) SpeciesCount::query()->whereIn('trip_report_id', $tripReportIds)->sum('count');
    }

    private function assertValidationFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
