<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\Contracts\AI\ParserDiagnosticReviewer;
use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserDiagnosticReviewProviderResponseData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ParserDiagnosticType;
use App\Enums\ScrapeRunType;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Jobs\ReviewParserDiagnosticsJob;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\TripReport;
use App\Services\Parsing\DiagnosticContextFactory;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\TripReportNormalizer;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParserDiagnosticPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_review_dispatch_is_after_commit_and_does_not_replace_deterministic_parsing(): void
    {
        config()->set('fish.ai_review.dispatch_enabled', true);
        Queue::fake();
        $payload = $this->payload('<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>');
        $run = ParserDiagnosticReviewRun::factory()->create([
            'raw_scrape_payload_id' => $payload->id,
            'status' => ParserDiagnosticReviewRunStatus::Preparing,
        ]);

        $result = app(ParseRawPayloadAction::class)->handle($payload->id, false, $run->id);

        $this->assertSame(1, $result->parsedReportCount);
        $this->assertSame(1, $result->diagnosticCount);
        $this->assertSame(ParserDiagnosticReviewRunStatus::Queued, $run->refresh()->status);
        Queue::assertPushed(
            DispatchParserDiagnosticReviewBatchesJob::class,
            fn (DispatchParserDiagnosticReviewBatchesJob $job): bool => $job->rawScrapePayloadId === $payload->id
                && $job->parserDiagnosticReviewRunId === $run->id
                && $job->afterCommit === true,
        );
    }

    public function test_openai_outage_cannot_undo_deterministic_parsing_or_deduplication(): void
    {
        config()->set('fish.ai_review.enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', false);
        config()->set('services.openai.api_key', 'test-key');
        Queue::fake();
        $payload = $this->payload('<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>');

        $result = app(ParseRawPayloadAction::class)->handle($payload->id, false);
        (new DeduplicateTripReportsJob('2026-07-12'))->handle(app(TripReportNormalizer::class));

        config()->set('fish.ai_review.dispatch_enabled', true);
        $this->app->bind(ParserDiagnosticReviewer::class, fn () => new class implements ParserDiagnosticReviewer
        {
            public function review(array $requests): ParserDiagnosticReviewProviderResponseData
            {
                throw new ConnectionException('OpenAI is unavailable.');
            }
        });

        try {
            app()->call([new ReviewParserDiagnosticsJob($payload->id), 'handle']);
            $this->fail('Expected the simulated provider outage.');
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $result->parsedReportCount);
        $this->assertSame(1, $result->diagnosticCount);
        $this->assertDatabaseHas('trip_reports', [
            'raw_scrape_payload_id' => $payload->id,
            'is_deduped_primary' => true,
        ]);
        $this->assertDatabaseHas('parser_errors', [
            'raw_scrape_payload_id' => $payload->id,
            'raw_value' => 'Moon Fish',
        ]);
    }

    public function test_repeated_unknown_values_in_different_paragraphs_have_distinct_stable_occurrences(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', false);
        $payload = $this->payload(implode("\n", [
            '<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>',
            '<p>The Dolphin returned with 4 Moon Fish and 2 Rockfish for 21 anglers on a Full Day trip.</p>',
        ]));
        Queue::fake();
        $action = app(ParseRawPayloadAction::class);

        $first = $action->handle($payload->id, false);
        $firstFingerprints = ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->where('error_type', 'unknown_species_alias')
            ->orderBy('report_fingerprint')
            ->pluck('diagnostic_fingerprint')
            ->all();
        $second = $action->handle($payload->id, false);
        $secondFingerprints = ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->where('error_type', 'unknown_species_alias')
            ->orderBy('report_fingerprint')
            ->pluck('diagnostic_fingerprint')
            ->all();

        $this->assertSame(2, $first->diagnosticCount);
        $this->assertSame(2, $second->diagnosticCount);
        $this->assertCount(2, array_unique($firstFingerprints));
        $this->assertSame($firstFingerprints, $secondFingerprints);
        $this->assertSame(
            [20, 21],
            ParserError::query()
                ->where('error_type', 'unknown_species_alias')
                ->get()
                ->map(fn (ParserError $error): int => $error->context['extracted_fields']['anglers'])
                ->sort()
                ->values()
                ->all(),
        );
    }

    public function test_database_rejects_duplicate_diagnostic_fingerprints(): void
    {
        $payload = $this->payload('<p>The Dolphin returned with 4 Moon Fish for 20 anglers.</p>');
        $attributes = [
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Moon Fish',
            'message' => 'Unknown species alias [Moon Fish].',
            'report_fingerprint' => hash('sha256', 'report'),
            'diagnostic_fingerprint' => hash('sha256', 'diagnostic'),
        ];
        ParserError::query()->create($attributes);

        $this->expectException(QueryException::class);

        ParserError::query()->create($attributes);
    }

    public function test_suspicious_flag_can_be_disabled_without_disabling_unknown_aliases(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', false);
        $payload = $this->payload('<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>');
        Queue::fake();

        app(ParseRawPayloadAction::class)->handle($payload->id, false);

        $this->assertDatabaseHas('parser_errors', ['error_type' => 'unknown_species_alias', 'raw_value' => 'Moon Fish']);
        $this->assertDatabaseMissing('parser_errors', ['error_type' => ParserDiagnosticType::StructuredSourceFallback->value]);
    }

    public function test_clean_narrative_report_has_no_false_positive_with_suspicious_rules_enabled(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = $this->payload('<p>The Dolphin returned with 4 Rockfish for 20 anglers on a Full Day trip.</p>');
        Queue::fake();

        $result = app(ParseRawPayloadAction::class)->handle($payload->id, false);

        $this->assertSame(1, $result->parsedReportCount);
        $this->assertSame(0, $result->diagnosticCount);
        $this->assertDatabaseEmpty('parser_errors');
    }

    public function test_seaforth_six_pack_report_is_parsed_without_diagnostics_or_ai_review(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        config()->set('fish.ai_review.dispatch_enabled', true);
        $body = '<ul><li>The <em>El Gato Dos</em> returned on a full day 6 pack charter with 5 anglers and they reported 10 Dorado, and 4 Yellowtail.</li></ul>';
        $payload = $this->payload(
            body: $body,
            sourceSlug: 'seaforth_landing',
            landingSlug: 'seaforth-sportfishing',
            boatName: 'El Gato Dos',
        );
        Queue::fake();

        $result = app(ParseRawPayloadAction::class)->handle($payload->id, false);
        $report = TripReport::query()
            ->with(['boat', 'speciesCounts.species'])
            ->where('raw_scrape_payload_id', $payload->id)
            ->firstOrFail();

        $this->assertSame(1, $result->parsedReportCount);
        $this->assertSame(0, $result->diagnosticCount);
        $this->assertSame('El Gato Dos', $report->boat->name);
        $this->assertSame(5, $report->anglers);
        $this->assertSame(
            ['Dorado' => 10, 'Yellowtail' => 4],
            $report->speciesCounts
                ->mapWithKeys(fn ($count): array => [$count->species->name => $count->count])
                ->sortKeys()
                ->all(),
        );
        $this->assertDatabaseEmpty('parser_errors');
        Queue::assertNotPushed(DispatchParserDiagnosticReviewBatchesJob::class);
    }

    public function test_source_specific_evidence_detects_an_empty_result_without_a_global_threshold(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $body = '<p>The Pacific Queen returned this morning with 49 Yellowtail for 30 anglers on a 1.5 Day charter.</p>';
        $storedPayload = $this->payload($body);
        $rawPayload = $this->rawPayloadData($storedPayload);
        $parsed = new ParsedFishCountCollection(collect(), 'source-specific-fishermans_landing-v2', 'narrative');

        $diagnostics = app(ParsedReportValidator::class)->validate($storedPayload, $rawPayload, $parsed);

        $this->assertCount(1, $diagnostics);
        $this->assertSame(ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet, $diagnostics[0]->type);
        $this->assertSame('narrative_report_paragraph', $diagnostics[0]->context['evidence']['evidence_strategy']);

        $rawPayloadForUnsupportedSource = new RawPayloadData(
            sourceKey: 'unsupported_source',
            targetDate: $rawPayload->targetDate,
            url: $rawPayload->url,
            body: $body,
        );

        $this->assertSame([], app(ParsedReportValidator::class)->validate($storedPayload, $rawPayloadForUnsupportedSource, $parsed));

        $paragraphWithoutSpeciesEvidence = new RawPayloadData(
            sourceKey: $rawPayload->sourceKey,
            targetDate: $rawPayload->targetDate,
            url: $rawPayload->url,
            body: '<p>The Pacific Queen returned with 30 anglers on a 1.5 Day trip.</p>',
        );

        $this->assertSame([], app(ParsedReportValidator::class)->validate($storedPayload, $paragraphWithoutSpeciesEvidence, $parsed));
    }

    public function test_source_specific_evidence_does_not_treat_a_partial_numeric_span_as_a_parsed_report(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $body = '<p>The Constitution returned with 110 Bluefin Tuna for 20 anglers on a 3 Day trip.</p>';
        $storedPayload = $this->payload($body);
        $rawPayload = $this->rawPayloadData($storedPayload);
        $report = $this->report(species: 'Bluefin Tuna', retained: 10, rawText: '10 Bluefin Tuna');
        $parsed = new ParsedFishCountCollection(
            collect([$report]),
            'source-specific-fishermans_landing-v2',
            'narrative',
        );

        $diagnostics = app(ParsedReportValidator::class)->validate($storedPayload, $rawPayload, $parsed);

        $this->assertCount(1, $diagnostics);
        $this->assertSame(ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet, $diagnostics[0]->type);
        $this->assertSame(
            'The Constitution returned with 110 Bluefin Tuna for 20 anglers on a 3 Day trip.',
            $diagnostics[0]->context['sanitized_paragraph'],
        );
    }

    public function test_context_is_sanitized_bounded_utf8_safe_and_contains_only_review_provenance(): void
    {
        config()->set('fish.parsing.diagnostics.max_paragraph_length', 80);
        $factory = app(DiagnosticContextFactory::class);
        $paragraph = '<script>authorization: Bearer-secret</script><p>Cookie=session-secret '.Str::repeat('é', 100).'</p>';
        $payload = new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://user:password@www.fishermanslanding.com/fishcounts.php?token=secret',
            body: $paragraph,
        );
        $report = $this->report(rawText: 'Cookie=session-secret '.Str::repeat('é', 100));
        $sanitized = $factory->paragraphForReport($payload, $report);

        $this->assertLessThanOrEqual(80, Str::length($sanitized));
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
        $this->assertStringNotContainsString('session-secret', $sanitized);
        $this->assertStringNotContainsString('Bearer-secret', $sanitized);
        $this->assertSame('Cookie=[redacted]', $factory->sanitizeDiagnosticText('Cookie: a=1; b=2'));
        $this->assertSame('Authorization=[redacted]', $factory->sanitizeDiagnosticText('Authorization: Bearer secret'));

        $validationData = new ParsedReportValidationData(
            payload: $payload,
            parsed: new ParsedFishCountCollection(collect([$report]), 'parser-v2', 'narrative'),
            report: $report,
            reportIndex: 0,
            parserVersion: 'parser-v2',
            format: 'narrative',
            sourceIdentifier: null,
            sanitizedParagraph: $sanitized,
        );
        $context = $factory->context($validationData, ['matched' => false]);

        $this->assertSame('https://www.fishermanslanding.com/fishcounts.php', $context['url']);
        $this->assertSame([
            'source', 'date', 'url', 'parser_version', 'format', 'report_index', 'source_identifier',
            'sanitized_paragraph', 'extracted_fields', 'evidence',
        ], array_keys($context));
    }

    public function test_context_uses_report_identity_to_disambiguate_duplicate_count_spans(): void
    {
        $factory = app(DiagnosticContextFactory::class);
        $payload = new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.fishermanslanding.com/fishcounts.php?date=2026-07-12',
            body: implode('', [
                '<p>Dolphin | 20 anglers | Full Day | 4 Rockfish</p>',
                '<p>Sea Watch | 30 anglers | Full Day | 4 Rockfish</p>',
            ]),
        );
        $report = $this->report(boat: 'Sea Watch', anglers: 30);

        $paragraph = $factory->paragraphForReport($payload, $report);

        $this->assertSame('Sea Watch | 30 anglers | Full Day | 4 Rockfish', $paragraph);
    }

    public function test_context_prefers_the_exact_count_span_when_identity_fields_are_unavailable(): void
    {
        $factory = app(DiagnosticContextFactory::class);
        $payload = new RawPayloadData(
            sourceKey: 'sportfishingreport_landing_pages',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.sportfishingreport.com/dock_totals/boats.php',
            body: implode('', [
                '<p>18 Bluefin Tuna, 24 Yellowtail, 3 Dorado, 1 Yellowfin Tuna</p>',
                '<p>18 Bluefin Tuna</p>',
            ]),
        );
        $report = $this->report(rawText: '18 Bluefin Tuna', species: 'Bluefin Tuna', retained: 18);

        $paragraph = $factory->paragraphForReport($payload, $report);

        $this->assertSame('18 Bluefin Tuna', $paragraph);
    }

    public function test_fingerprints_are_stable_and_invalidate_for_parser_or_paragraph_changes(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', false);
        $storedPayload = $this->payload('<p>The Dolphin returned with 4 Moon Fish for 20 anglers on a Full Day trip.</p>');
        $rawPayload = $this->rawPayloadData($storedPayload);
        $report = $this->report(species: 'Moon Fish');
        $parsedV1 = new ParsedFishCountCollection(collect([$report]), 'parser-v1', 'narrative');
        $first = app(ParsedReportValidator::class)->validate($storedPayload, $rawPayload, $parsedV1)[0];
        $stable = app(ParsedReportValidator::class)->validate($storedPayload, $rawPayload, $parsedV1)[0];
        $versionChangedReport = $this->report(species: 'Moon Fish', metadata: ['parser' => 'parser-v2']);
        $versionChanged = app(ParsedReportValidator::class)->validate(
            $storedPayload,
            $rawPayload,
            new ParsedFishCountCollection(collect([$versionChangedReport]), 'parser-v2', 'narrative'),
        )[0];
        $paragraphChangedPayload = new RawPayloadData(
            sourceKey: $rawPayload->sourceKey,
            targetDate: $rawPayload->targetDate,
            url: $rawPayload->url,
            body: '<p>The Dolphin returned with 5 Moon Fish for 20 anglers on a Full Day trip.</p>',
        );
        $paragraphChangedReport = $this->report(species: 'Moon Fish', retained: 5, rawText: '5 Moon Fish');
        $paragraphChanged = app(ParsedReportValidator::class)->validate(
            $storedPayload,
            $paragraphChangedPayload,
            new ParsedFishCountCollection(collect([$paragraphChangedReport]), 'parser-v1', 'narrative'),
        )[0];

        $this->assertSame($first->diagnosticFingerprint, $stable->diagnosticFingerprint);
        $this->assertNotSame($first->diagnosticFingerprint, $versionChanged->diagnosticFingerprint);
        $this->assertNotSame($first->diagnosticFingerprint, $paragraphChanged->diagnosticFingerprint);
    }

    private function payload(
        string $body,
        string $sourceSlug = 'fishermans_landing',
        string $landingSlug = 'fishermans-landing',
        string $boatName = 'Dolphin',
    ): RawScrapePayload {
        $this->seed(DatabaseSeeder::class);
        $source = ScrapeSource::query()->where('slug', $sourceSlug)->firstOrFail();
        $landing = Landing::query()->where('slug', $landingSlug)->firstOrFail();
        Boat::query()->firstOrCreate(
            ['slug' => Str::slug($boatName)],
            ['landing_id' => $landing->id, 'name' => $boatName],
        );
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-12',
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-12',
            'url' => "{$source->base_url}/fishcounts.php?date=2026-07-12",
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => now(),
        ]);
    }

    private function rawPayloadData(RawScrapePayload $payload): RawPayloadData
    {
        return new RawPayloadData(
            sourceKey: $payload->scrapeSource->slug,
            targetDate: CarbonImmutable::parse($payload->target_date),
            url: $payload->url,
            body: $payload->payload,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function report(
        string $species = 'Rockfish',
        int $retained = 4,
        string $rawText = '4 Rockfish',
        array $metadata = ['parser' => 'parser-v1'],
        string $boat = 'Dolphin',
        int $anglers = 20,
    ): ParsedTripReportData {
        return new ParsedTripReportData(
            sourceKey: 'fishermans_landing',
            tripDate: CarbonImmutable::parse('2026-07-12'),
            regionName: 'San Diego',
            landingName: "Fisherman's Landing",
            boatName: $boat,
            tripTypeName: 'Full Day',
            anglers: $anglers,
            rawFishCountText: $rawText,
            speciesCounts: [new ParsedSpeciesCountData($species, $retained, 0, $rawText)],
            metadata: $metadata,
        );
    }
}
