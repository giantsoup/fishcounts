<?php

namespace Tests\Feature;

use App\Actions\Boats\ConsolidateBoatAlias;
use App\Actions\Parsing\AutomateParserDiagnosticReviews;
use App\Actions\Parsing\ParseRawPayloadAction;
use App\Contracts\AI\ParserDiagnosticReviewer;
use App\DTOs\ParserDiagnosticReviewProviderResponseData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserErrorResolutionType;
use App\Enums\Role;
use App\Enums\ScrapeRunType;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewAction;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use App\Models\User;
use App\Services\AI\ParserAliasAutomationGate;
use Database\Seeders\DatabaseSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ParserAliasAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config()->set('fish.ai_review.dispatch_enabled', false);
        config()->set('fish.ai_review.human_review_enabled', true);
        config()->set('fish.ai_review.automation.enabled', true);
        config()->set('fish.ai_review.automation.species_enabled', true);
        config()->set('fish.ai_review.automation.trip_types_enabled', true);
        config()->set('fish.ai_review.automation.boats_enabled', true);
        config()->set('fish.ai_review.automation.minimum_human_reviewed_sample', 0);
        config()->set('fish.ai_review.automation.minimum_confidence', 0.98);
        config()->set('fish.ai_review.automation.freshness_hours', 24);
    }

    /** @return array<string, array{float, bool}> */
    public static function confidenceThresholdCases(): array
    {
        return [
            'immediately below' => [0.9799, false],
            'exactly at threshold' => [0.98, true],
            'above threshold' => [0.9900, true],
        ];
    }

    #[DataProvider('confidenceThresholdCases')]
    public function test_confidence_threshold_is_inclusive(float $confidence, bool $expectedToApply): void
    {
        [$payload, $parserError] = $this->payloadAndError('Moon Fish');
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $species->id, $confidence);
        $speciesCount = Species::query()->count();

        $applied = app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]);

        $this->assertSame($expectedToApply ? 1 : 0, $applied);
        $this->assertSame($speciesCount, Species::query()->count());
        $this->assertSame($expectedToApply, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->exists());

        if (! $expectedToApply) {
            $this->assertNull($parserError->refresh()->resolved_at);

            return;
        }

        $this->assertSame(ParserErrorResolutionType::AiAssistedAlias, $parserError->refresh()->resolution_type);
        $this->assertNull($parserError->resolved_by_user_id);
        $automaticAction = $review->humanActions()->sole();
        $this->assertSame(ParserDiagnosticReviewActionType::AutomaticallyAccepted, $automaticAction->action);
        $this->assertSame('Luna automation', $automaticAction->actor_name);
        $this->assertSame($review->id, data_get($automaticAction->details, 'review_id'));
        $this->assertSame($parserError->diagnostic_fingerprint, data_get($automaticAction->details, 'diagnostic_fingerprint'));

        $tripReportCount = $payload->tripReports()->count();
        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));
        $this->assertSame(1, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->count());
        $this->assertSame(1, $review->humanActions()->count());
        $this->assertSame($tripReportCount, $payload->tripReports()->count());
        $this->assertFalse(ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->where('raw_value', 'Moon Fish')
            ->whereNull('resolution_type')
            ->exists());
    }

    public function test_payload_hash_migration_reverses_and_reapplies_cleanly(): void
    {
        $migration = require database_path('migrations/2026_07_13_011914_add_payload_hash_to_parser_diagnostic_reviews_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('parser_diagnostic_reviews', 'payload_hash'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('parser_diagnostic_reviews', 'payload_hash'));
    }

    public function test_dynamic_sample_gate_requires_fifty_qualifying_reviews_and_no_rejection(): void
    {
        config()->set('fish.ai_review.automation.minimum_human_reviewed_sample', 50);
        $admin = User::factory()->admin()->create();
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        [$payload, $parserError] = $this->payloadAndError('Moon Fish');
        $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $species->id);

        $this->recordHistoricalRecommendations($payload, $species, $admin, 49);
        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));
        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);

        $this->recordHistoricalRecommendations($payload, $species, $admin, 1, 49);
        $this->assertSame(1, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));

        [$secondPayload, $secondError] = $this->payloadAndError('Sun Fish', '2026-07-13');
        $secondReview = $this->review($secondPayload, $secondError, CanonicalEntityType::Species, $species->id);
        $this->recordHistoricalRecommendations($payload, $species, $admin, 1, 50, ParserDiagnosticReviewActionType::Rejected);

        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($secondPayload->id, [$secondReview->id]));
        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'sun fish']);
    }

    public function test_global_and_entity_specific_switches_are_independent(): void
    {
        [$payload, $parserError] = $this->payloadAndError('Moon Fish');
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $species->id);

        config()->set('fish.ai_review.automation.enabled', false);
        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));

        config()->set('fish.ai_review.automation.enabled', true);
        config()->set('fish.ai_review.automation.species_enabled', false);
        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));

        config()->set('fish.ai_review.automation.species_enabled', true);
        config()->set('fish.ai_review.automation.trip_types_enabled', false);
        config()->set('fish.ai_review.automation.boats_enabled', false);
        $gate = app(ParserAliasAutomationGate::class);
        $this->assertTrue($gate->allows(CanonicalEntityType::Species));
        $this->assertFalse($gate->allows(CanonicalEntityType::TripType));
        $this->assertFalse($gate->allows(CanonicalEntityType::Boat));

        config()->set('fish.ai_review.automation.species_enabled', false);
        config()->set('fish.ai_review.automation.trip_types_enabled', true);
        config()->set('fish.ai_review.automation.boats_enabled', true);
        $this->assertFalse($gate->allows(CanonicalEntityType::Species));
        $this->assertTrue($gate->allows(CanonicalEntityType::TripType));
        $this->assertTrue($gate->allows(CanonicalEntityType::Boat));

        config()->set('fish.ai_review.automation.species_enabled', true);
        config()->set('fish.ai_review.enabled', false);
        config()->set('fish.ai_review.dispatch_enabled', false);
        config()->set('fish.github_issues.enabled', false);
        $this->assertSame(1, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));
    }

    public function test_species_trip_type_and_safe_boat_aliases_apply_in_one_batch_without_creating_entities(): void
    {
        [$payload, $speciesError] = $this->payloadAndError('Moon Fish');
        $tripTypeError = $this->error($payload, 'unknown_trip_type_alias', 'trip_type', 'All Day Special', 'trip-type');
        $boatError = $this->error($payload, 'unknown_boat_alias', 'boat', 'Sea Watcher', 'boat');
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $tripType = TripType::query()->where('slug', 'full-day')->firstOrFail();
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $reviews = [
            $this->review($payload, $speciesError, CanonicalEntityType::Species, $species->id),
            $this->review($payload, $tripTypeError, CanonicalEntityType::TripType, $tripType->id),
            $this->review($payload, $boatError, CanonicalEntityType::Boat, $boat->id),
        ];
        $counts = [Species::query()->count(), TripType::query()->count(), Boat::query()->count()];

        $applied = app(AutomateParserDiagnosticReviews::class)->handle(
            $payload->id,
            array_map(fn (ParserDiagnosticReview $review): int => $review->id, $reviews),
        );

        $this->assertSame(3, $applied);
        $this->assertSame($counts, [Species::query()->count(), TripType::query()->count(), Boat::query()->count()]);
        $this->assertSame($species->id, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->value('species_id'));
        $this->assertSame($tripType->id, TripTypeAlias::query()->where('normalized_alias', 'all day special')->value('trip_type_id'));
        $this->assertSame($boat->id, BoatAlias::query()->where('normalized_alias', 'sea watcher')->value('boat_id'));
        $this->assertTrue($boat->refresh()->is_active);
    }

    public function test_successful_review_job_immediately_runs_guarded_automation(): void
    {
        Queue::fake();
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('fish.ai_review.budgets.estimated_request_cost_micros', 1000);
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 10000);
        [$payload, $parserError] = $this->payloadAndError('Moon Fish');
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class($parserError->diagnostic_fingerprint, $species->id) implements ParserDiagnosticReviewer
        {
            public function __construct(private readonly string $fingerprint, private readonly int $speciesId) {}

            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                return new ParserDiagnosticReviewProviderResponseData(
                    responseId: 'resp_automation',
                    model: 'gpt-5.6-luna',
                    results: [$this->fingerprint => [
                        'classification' => 'legitimate_alias',
                        'confidence' => 0.98,
                        'rationale' => 'Moon Fish is an alias for Opaleye.',
                        'corrections' => [[
                            'operation' => 'map_alias',
                            'report_index' => 0,
                            'field' => 'species',
                            'canonical_type' => 'species',
                            'canonical_id' => $this->speciesId,
                            'value' => null,
                            'retained_count' => null,
                            'released_count' => null,
                        ]],
                    ]],
                    refused: false,
                    refusal: null,
                    inputTokens: 100,
                    cachedInputTokens: 0,
                    outputTokens: 30,
                    reasoningTokens: 10,
                    totalTokens: 130,
                );
            }
        });

        app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);

        $review = ParserDiagnosticReview::query()->sole();
        $this->assertSame(ParserDiagnosticReviewStatus::Succeeded, $review->status);
        $this->assertSame($payload->payload_hash, $review->payload_hash);
        $this->assertDatabaseHas('species_aliases', [
            'species_id' => $species->id,
            'normalized_alias' => 'moon fish',
        ]);
        $this->assertSame(ParserErrorResolutionType::AiAssistedAlias, $parserError->refresh()->resolution_type);
        $this->assertSame(ParserDiagnosticReviewActionType::AutomaticallyAccepted, $review->humanActions()->sole()->action);
    }

    public function test_reparse_failure_rolls_back_the_alias_resolution_and_audit(): void
    {
        [$payload, $parserError] = $this->payloadAndError('Moon Fish');
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $species->id);
        $this->mock(ParseRawPayloadAction::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('Reparse failed.'));
        });

        try {
            app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]);
            $this->fail('The reparse failure must be propagated after rolling back automation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Reparse failed.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
        $this->assertNull($parserError->refresh()->resolved_at);
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
    }

    public function test_stale_inactive_wrong_type_missing_and_conflicting_targets_stay_manual(): void
    {
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);

        [$stalePayload, $staleError] = $this->payloadAndError('Stale Fish');
        $staleReview = $this->review($stalePayload, $staleError, CanonicalEntityType::Species, $species->id, completedAt: now()->subHours(25));

        [$inactivePayload, $inactiveError] = $this->payloadAndError('Inactive Fish', '2026-07-13');
        $inactiveSpecies = Species::query()->create(['name' => 'Inactive', 'slug' => 'inactive']);
        $inactiveReview = $this->review($inactivePayload, $inactiveError, CanonicalEntityType::Species, $inactiveSpecies->id);
        $inactiveSpecies->update(['is_active' => false]);

        [$wrongTypePayload, $wrongTypeError] = $this->payloadAndError('Wrong Fish', '2026-07-14');
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $wrongTypeReview = $this->review($wrongTypePayload, $wrongTypeError, CanonicalEntityType::Boat, $boat->id);

        [$missingPayload, $missingError] = $this->payloadAndError('Missing Fish', '2026-07-15');
        $missingSpecies = Species::query()->create(['name' => 'Temporary', 'slug' => 'temporary']);
        $missingReview = $this->review($missingPayload, $missingError, CanonicalEntityType::Species, $missingSpecies->id);
        $missingSpecies->delete();

        [$conflictPayload, $conflictError] = $this->payloadAndError('Conflict Fish', '2026-07-16');
        $conflictReview = $this->review($conflictPayload, $conflictError, CanonicalEntityType::Species, $species->id);
        $otherSpecies = Species::query()->create(['name' => 'Other', 'slug' => 'other']);
        SpeciesAlias::query()->create(['species_id' => $otherSpecies->id, 'alias' => 'Conflict Fish', 'normalized_alias' => 'conflict fish']);

        [$futurePayload, $futureError] = $this->payloadAndError('Future Fish', '2026-07-17');
        $futureReview = $this->review($futurePayload, $futureError, CanonicalEntityType::Species, $species->id, completedAt: now()->addMinute());

        [$changedPayload, $changedError] = $this->payloadAndError('Changed Fish', '2026-07-18');
        $changedReview = $this->review($changedPayload, $changedError, CanonicalEntityType::Species, $species->id);
        $changedPayload->update(['payload_hash' => hash('sha256', 'changed-payload')]);

        foreach ([
            [$stalePayload, $staleReview],
            [$inactivePayload, $inactiveReview],
            [$wrongTypePayload, $wrongTypeReview],
            [$missingPayload, $missingReview],
            [$conflictPayload, $conflictReview],
            [$futurePayload, $futureReview],
            [$changedPayload, $changedReview],
        ] as [$payload, $review]) {
            $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));
        }

        $this->assertNull($staleError->refresh()->resolved_at);
        $this->assertNull($inactiveError->refresh()->resolved_at);
        $this->assertNull($wrongTypeError->refresh()->resolved_at);
        $this->assertNull($missingError->refresh()->resolved_at);
        $this->assertNull($conflictError->refresh()->resolved_at);
        $this->assertNull($futureError->refresh()->resolved_at);
        $this->assertNull($changedError->refresh()->resolved_at);
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
    }

    public function test_target_outside_the_candidates_sent_for_the_diagnostic_stays_manual(): void
    {
        config()->set('fish.ai_review.limits.max_candidates', 1);
        [$payload, $parserError] = $this->payloadAndError('Moon Fish');
        $target = Species::query()->create(['name' => 'Late Candidate', 'slug' => 'late-candidate']);
        $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $target->id);

        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));
        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
        $this->assertNull($parserError->refresh()->resolved_at);
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
    }

    public function test_uncertain_and_new_entity_results_stay_in_human_review(): void
    {
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $speciesCount = Species::query()->count();

        foreach ([
            ParserDiagnosticReviewClassification::Uncertain,
            ParserDiagnosticReviewClassification::NewEntityCandidate,
        ] as $index => $classification) {
            [$payload, $parserError] = $this->payloadAndError("Manual Fish {$index}", "2026-07-2{$index}");
            $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $species->id);
            $review->forceFill([
                'classification' => $classification,
                'validated_result' => [
                    'classification' => $classification->value,
                    'confidence' => 0.99,
                    'rationale' => 'An administrator must handle this result.',
                    'corrections' => [],
                ],
            ])->save();

            $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));
            $this->assertNull($parserError->refresh()->resolved_at);
        }

        $this->assertSame($speciesCount, Species::query()->count());
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
    }

    public function test_boat_automation_refuses_a_mapping_that_would_consolidate_a_boat(): void
    {
        [$payload, $parserError] = $this->payloadAndError('Sea Watcher', errorType: 'unknown_boat_alias', field: 'boat');
        $target = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $variant = Boat::query()->create(['name' => 'Sea Watcher', 'slug' => 'sea-watcher']);
        $review = $this->review($payload, $parserError, CanonicalEntityType::Boat, $target->id);

        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]));
        $this->assertDatabaseMissing('boat_aliases', ['normalized_alias' => 'sea watcher']);
        $this->assertTrue($target->refresh()->is_active);
        $this->assertTrue($variant->refresh()->is_active);
        $this->assertNull($parserError->refresh()->resolved_at);
    }

    public function test_boat_domain_action_rechecks_no_consolidation_mode_under_its_own_locks(): void
    {
        $target = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $variant = Boat::query()->create(['name' => 'Ocean Queen', 'slug' => 'ocean-queen']);
        BoatAlias::query()->create([
            'boat_id' => $variant->id,
            'alias' => 'The Ocean Queen',
            'normalized_alias' => 'the ocean queen',
        ]);

        try {
            app(ConsolidateBoatAlias::class)->handle(
                $target,
                'The Ocean Queen',
                'the ocean queen',
                null,
                ParserErrorResolutionType::AiAssistedAlias,
                false,
            );
            $this->fail('Boat automation must not consolidate an existing alias.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('alias', $exception->errors());
        }

        $this->assertSame($variant->id, BoatAlias::query()->where('normalized_alias', 'the ocean queen')->value('boat_id'));
        $this->assertTrue($variant->refresh()->is_active);
        $this->assertTrue($target->refresh()->is_active);
    }

    public function test_competing_reviews_for_the_same_alias_create_one_alias_and_one_automatic_audit(): void
    {
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        [$firstPayload, $firstError] = $this->payloadAndError('Moon Fish');
        [$secondPayload, $secondError] = $this->payloadAndError('Moon Fish', '2026-07-13');
        $firstReview = $this->review($firstPayload, $firstError, CanonicalEntityType::Species, $species->id);
        $secondReview = $this->review($secondPayload, $secondError, CanonicalEntityType::Species, $species->id);

        $this->assertSame(1, app(AutomateParserDiagnosticReviews::class)->handle($firstPayload->id, [$firstReview->id]));
        $this->assertSame(0, app(AutomateParserDiagnosticReviews::class)->handle($secondPayload->id, [$secondReview->id]));

        $this->assertSame(1, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->count());
        $this->assertSame(1, ParserDiagnosticReviewAction::query()
            ->where('action', ParserDiagnosticReviewActionType::AutomaticallyAccepted)
            ->count());
        $this->assertSame(ParserErrorResolutionType::AiAssistedAlias, $firstError->refresh()->resolution_type);
        $this->assertSame(ParserErrorResolutionType::AiAssistedAlias, $secondError->refresh()->resolution_type);
        $this->assertTrue($firstPayload->tripReports()->exists());
        $this->assertTrue($secondPayload->tripReports()->exists());
    }

    public function test_concurrent_reviews_for_the_same_alias_are_serialized(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PCNTL extension is required for the concurrency test.');
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'fishcounts-phase7-');
        $this->assertNotFalse($databasePath);
        $originalConnection = config('database.default');
        $originalCacheStore = config('cache.default');

        try {
            config()->set('database.connections.phase7_concurrency', [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
            ]);
            config()->set('database.default', 'phase7_concurrency');
            config()->set('cache.default', 'file');
            DB::purge('phase7_concurrency');
            Cache::forgetDriver('file');
            Artisan::call('migrate:fresh', [
                '--database' => 'phase7_concurrency',
                '--force' => true,
            ]);
            $this->seed(DatabaseSeeder::class);

            $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
            [$firstPayload, $firstError] = $this->payloadAndError('Moon Fish');
            [$secondPayload, $secondError] = $this->payloadAndError('Moon Fish', '2026-07-13');
            $work = [
                [$firstPayload->id, $this->review($firstPayload, $firstError, CanonicalEntityType::Species, $species->id)->id],
                [$secondPayload->id, $this->review($secondPayload, $secondError, CanonicalEntityType::Species, $species->id)->id],
            ];
            DB::disconnect('phase7_concurrency');
            Cache::forgetDriver('file');

            $processes = [];
            foreach ($work as [$payloadId, $reviewId]) {
                $processId = pcntl_fork();
                $this->assertNotSame(-1, $processId);

                if ($processId === 0) {
                    try {
                        DB::purge('phase7_concurrency');
                        Cache::forgetDriver('file');
                        $result = app(AutomateParserDiagnosticReviews::class)->handle($payloadId, [$reviewId]);
                        file_put_contents($databasePath.".result.{$reviewId}", (string) $result);
                        exit(0);
                    } catch (\Throwable $throwable) {
                        file_put_contents($databasePath.".error.{$reviewId}", $throwable::class.': '.$throwable->getMessage());
                        exit(1);
                    }
                }

                $processes[$processId] = $reviewId;
            }

            $results = [];
            foreach ($processes as $processId => $reviewId) {
                pcntl_waitpid($processId, $status);
                $errorPath = $databasePath.".error.{$reviewId}";
                $resultPath = $databasePath.".result.{$reviewId}";
                $error = is_file($errorPath) ? file_get_contents($errorPath) : null;
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, (string) $error);
                $results[] = is_file($resultPath) ? (int) file_get_contents($resultPath) : null;

                foreach ([$errorPath, $resultPath] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }

            DB::purge('phase7_concurrency');
            Cache::forgetDriver('file');
            sort($results);
            $this->assertSame([0, 1], $results);
            $this->assertSame(1, SpeciesAlias::query()->where('normalized_alias', 'moon fish')->count());
            $this->assertSame(1, ParserDiagnosticReviewAction::query()
                ->where('action', ParserDiagnosticReviewActionType::AutomaticallyAccepted)
                ->count());
        } finally {
            DB::disconnect('phase7_concurrency');
            Cache::forgetDriver('file');
            config()->set('database.default', $originalConnection);
            config()->set('cache.default', $originalCacheStore);
            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }
    }

    public function test_only_an_admin_can_explicitly_reverse_the_recorded_automatic_alias(): void
    {
        [$payload, $parserError] = $this->payloadAndError('Moon Fish');
        $species = Species::query()->create(['name' => 'Opaleye', 'slug' => 'opaleye']);
        $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $species->id);
        app(AutomateParserDiagnosticReviews::class)->handle($payload->id, [$review->id]);
        $automaticAction = $review->humanActions()->sole();
        $route = route('admin.parser-errors.reviews.reverse-automation', [$parserError, $review, $automaticAction]);
        config()->set('fish.ai_review.human_review_enabled', false);
        config()->set('fish.ai_review.automation.enabled', false);

        $this->actingAs(User::factory()->create(['role' => Role::User]))->post($route)->assertForbidden();
        $this->assertDatabaseHas('species_aliases', ['normalized_alias' => 'moon fish']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index', ['status' => 'all']))
            ->assertOk()
            ->assertSeeText('Automatically Accepted by Luna automation')
            ->assertSeeText('Reverse automatic alias');
        $this->actingAs($admin)
            ->post($route)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'The automatic alias resolution was reversed. Affected payloads were reparsed and deduplicated.');

        $this->assertDatabaseMissing('species_aliases', ['normalized_alias' => 'moon fish']);
        $reversal = $review->humanActions()->where('action', ParserDiagnosticReviewActionType::AutomationReversed)->sole();
        $this->assertSame($admin->id, $reversal->actor_user_id);
        $this->assertSame($automaticAction->id, data_get($reversal->details, 'automatic_action_id'));
        $this->assertSame(1, Species::query()->whereKey($species->id)->count());
        $this->assertDatabaseHas('parser_errors', [
            'raw_scrape_payload_id' => $payload->id,
            'raw_field' => 'species',
            'raw_value' => 'Moon Fish',
            'resolved_at' => null,
            'resolution_type' => null,
        ]);
    }

    /** @return array{RawScrapePayload, ParserError} */
    private function payloadAndError(
        string $rawValue,
        string $date = '2026-07-12',
        string $errorType = 'unknown_species_alias',
        string $field = 'species',
    ): array {
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => $date,
        ]);
        $body = "<p>The Dolphin returned with 4 {$rawValue} for 20 anglers on a Full Day trip.</p>";
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $date,
            'url' => "https://www.fishermanslanding.com/fishcounts.php?date={$date}",
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => now(),
        ]);

        return [$payload, $this->error($payload, $errorType, $field, $rawValue, "subject-{$date}-{$field}")];
    }

    private function error(
        RawScrapePayload $payload,
        string $errorType,
        string $field,
        string $rawValue,
        string $fingerprintSeed,
    ): ParserError {
        return ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'error_type' => $errorType,
            'raw_field' => $field,
            'raw_value' => $rawValue,
            'message' => 'Unknown alias.',
            'context' => ['sanitized_paragraph' => $rawValue, 'parser_version' => 'parser-v1'],
            'report_fingerprint' => hash('sha256', "report-{$fingerprintSeed}"),
            'diagnostic_fingerprint' => hash('sha256', "diagnostic-{$fingerprintSeed}"),
        ]);
    }

    private function review(
        RawScrapePayload $payload,
        ParserError $parserError,
        CanonicalEntityType $canonicalType,
        int $canonicalId,
        float $confidence = 0.98,
        ?DateTimeInterface $completedAt = null,
    ): ParserDiagnosticReview {
        $field = match ($canonicalType) {
            CanonicalEntityType::Boat => 'boat',
            CanonicalEntityType::Species => 'species',
            CanonicalEntityType::TripType => 'trip_type',
        };
        $result = [
            'classification' => ParserDiagnosticReviewClassification::LegitimateAlias->value,
            'confidence' => $confidence,
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

        return ParserDiagnosticReview::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'payload_hash' => $payload->payload_hash,
            'parser_error_id' => $parserError->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
            'status' => ParserDiagnosticReviewStatus::Succeeded,
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
            'classification' => ParserDiagnosticReviewClassification::LegitimateAlias,
            'confidence' => $confidence,
            'validated_result' => $result,
            'rationale' => $result['rationale'],
            'completed_at' => $completedAt ?? now(),
        ]);
    }

    private function recordHistoricalRecommendations(
        RawScrapePayload $payload,
        Species $species,
        User $actor,
        int $count,
        int $offset = 0,
        ParserDiagnosticReviewActionType $action = ParserDiagnosticReviewActionType::Accepted,
    ): void {
        for ($index = $offset; $index < $offset + $count; $index++) {
            $parserError = $this->error($payload, 'unknown_species_alias', 'species', "Historical {$index}", "historical-{$index}");
            $review = $this->review($payload, $parserError, CanonicalEntityType::Species, $species->id);
            ParserDiagnosticReviewAction::query()->create([
                'parser_diagnostic_review_id' => $review->id,
                'parser_error_id' => $parserError->id,
                'actor_user_id' => $actor->id,
                'actor_name' => $actor->name,
                'actor_email' => $actor->email,
                'action' => $action,
                'review_attempt' => $review->attempts,
                'details' => [
                    'classification' => ParserDiagnosticReviewClassification::LegitimateAlias->value,
                    'confidence' => 0.98,
                ],
            ]);
        }
    }
}
