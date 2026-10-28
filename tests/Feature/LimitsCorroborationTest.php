<?php

namespace Tests\Feature;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParseRawPayloadOptions;
use App\Enums\CatchQuantityBasis;
use App\Enums\ScrapeRunType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserError;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\TripReport;
use App\Services\AI\ParserDiagnosticReviewRequestFactory;
use App\Services\AI\ParserDiagnosticReviewRequestValidator;
use App\Services\Parsing\LimitsSourceReconciler;
use App\Services\Parsing\RawPayloadEvaluator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LimitsCorroborationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config()->set('limits.enabled', true);
        foreach (['Dolphin' => "Fisherman's Landing", 'Highliner' => 'Seaforth Sportfishing'] as $boat => $landing) {
            Boat::query()->create(['name' => $boat, 'slug' => str($boat)->slug()->toString(), 'landing_id' => Landing::query()->where('name', $landing)->value('id')]);
        }
        Http::preventStrayRequests();
        Queue::fake();
    }

    #[DataProvider('incidents')]
    public function test_explicit_matching_saved_source_supplies_limits_before_fallback_suppression(string $boat, string $source, string $landing, string $date, string $trip, string $direct, string $fallback, int $anglers, array $expected): void
    {
        $primary = $this->payload($source, $date, $direct);
        $support = $this->payload('sportfishingreport_landing_pages', $date, $this->partyRow($boat, $landing, $trip, $anglers, $fallback));
        $action = app(ParseRawPayloadAction::class);
        $options = ParseRawPayloadOptions::maintenance();
        $action->handleWithOptions($primary->id, $options);
        $action->handleWithOptions($support->id, $options);
        $this->assertSame(1, TripReport::query()->count(), json_encode(TripReport::query()->get(['raw_boat_name', 'boat_id', 'raw_trip_type', 'trip_type_id', 'landing_id', 'anglers', 'metadata'])->toArray()));
        $report = TripReport::query()->with('speciesCounts.species')->sole();
        $this->assertSame($anglers, $report->anglers);
        foreach ($expected as $name => $quantity) {
            $count = $report->speciesCounts->firstWhere('species.name', $name);
            $this->assertSame($quantity, $count->count);
            $this->assertSame(CatchQuantityBasis::Corroborated, $count->quantity_basis);
            $this->assertSame($support->id, $count->components[0]['evidence']['supporting_payload_id']);
        }
        $this->assertFalse($primary->parserErrors()->whereNull('resolution_type')->where('error_type', 'extracted_value_source_span_mismatch')->exists());
        $action->handleWithOptions($primary->id, $options);
        $this->assertSame(1, TripReport::query()->count());
        Queue::assertNothingPushed();
    }

    public static function incidents(): array
    {
        return [
            'Dolphin payloads 777 and 782' => ['Dolphin', 'fishermans_landing', "Fisherman's Landing", '2026-10-07', '3/4 Day', '<p>The Dolphin finished with limits of Calico Bass, Limits Bonito and 17 Yellowtail, 26 Whitefish, and 21 Sheephead on their 3/4 day trip for 25 anglers.</p>', '75 Calico Bass, 75 Bonito, 17 Yellowtail, 26 Whitefish, 21 Sheephead', 25, ['Calico Bass' => 75, 'Bonito' => 75]],
            'Highliner payloads 760 and 764' => ['Highliner', 'seaforth_landing', 'Seaforth Sportfishing', '2026-10-04', '1.5 Day', '<ul><li>The Highliner 1.5 Day trip finished with 16 bluefin tuna, 17 yellowfin tuna, 5 skipjack, 19 yellowtail and limits of reds.</li></ul>', '16 Bluefin Tuna, 17 Yellowfin Tuna, 5 Skipjack, 19 Yellowtail, 44 Vermilion Rockfish', 22, ['Rockfish' => 44]],
        ];
    }

    public function test_conflicting_anglers_and_ambiguous_matches_cannot_supply_a_count(): void
    {
        $direct = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $support = $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 20, '75 Calico Bass, 17 Yellowtail'));
        [, , $parsed] = app(RawPayloadEvaluator::class)->evaluate($direct->id);
        $result = app(LimitsSourceReconciler::class)->reconcile($parsed, $direct);
        $this->assertNull(collect($result->tripReports[0]->speciesCounts)->firstWhere('speciesName', 'Calico Bass')->count);
        $prefix = '<div class="panel"><h2>San Diego Fish Counts</h2>';
        $first = substr($this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, '75 Calico Bass, 17 Yellowtail'), strlen($prefix), -6);
        $second = substr($this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, '80 Calico Bass, 17 Yellowtail'), strlen($prefix), -6);
        $support->update(['payload' => $prefix.$first.$second.'</div>']);
        $result = app(LimitsSourceReconciler::class)->reconcile($parsed, $direct);
        $this->assertNull(collect($result->tripReports[0]->speciesCounts)->firstWhere('speciesName', 'Calico Bass')->count);
    }

    public function test_preview_is_read_only_and_preserves_explicit_limits_wording(): void
    {
        $payload = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught LIMITS (85) of Bonito on their 3/4 day trip for 17 anglers.</p>');
        $this->artisan('fish:limits-preview', ['payloads' => [$payload->id]])->expectsOutputToContain('"is_limits": true')->assertSuccessful();
        $this->assertSame(0, TripReport::query()->count());
        $this->assertSame(0, ParserExecution::query()->count());
        $this->assertSame(0, ParserError::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('releaseDirections')]
    public function test_released_limits_and_release_only_counts_cannot_become_retained_corroboration(string $claim, string $fallback): void
    {
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught '.$claim.', 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, $fallback));
        [, , $parsed] = app(RawPayloadEvaluator::class)->evaluate($primary->id);
        $reconciled = app(LimitsSourceReconciler::class)->reconcile($parsed, $primary);
        $count = collect($reconciled->tripReports[0]->speciesCounts)->firstWhere('speciesName', 'Calico Bass');
        $this->assertNull($count->count, $claim.' with '.$fallback);
    }

    public static function releaseDirections(): array
    {
        return [
            ['limits of Calico Bass released', '75 Calico Bass, 17 Yellowtail'],
            ['limits of Calico Bass', '20 Calico Bass Released, 17 Yellowtail'],
            ['limits of Calico Bass', '0 Calico Bass Released, 17 Yellowtail'],
        ];
    }

    public function test_conflicting_sources_produce_a_reviewable_diagnostic(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 20, '75 Calico Bass, 17 Yellowtail'));
        app(ParseRawPayloadAction::class)->handleWithOptions($primary->id, ParseRawPayloadOptions::maintenance());
        $error = $primary->parserErrors()->whereNull('resolution_type')->where('error_type', 'extracted_value_source_span_mismatch')->sole();
        $request = app(ParserDiagnosticReviewRequestFactory::class)->make($primary, $error);
        app(ParserDiagnosticReviewRequestValidator::class)->validate($request);
        $this->assertArrayHasKey('limits_source_conflict', $request->context['evidence']);
    }

    public function test_fallback_releases_survive_primary_limits_corroboration(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $support = $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, '75 Calico Bass, 50 Calico Bass Released, 17 Yellowtail'));
        $action = app(ParseRawPayloadAction::class);
        $action->handleWithOptions($support->id, ParseRawPayloadOptions::maintenance());
        $action->handleWithOptions($primary->id, ParseRawPayloadOptions::maintenance());
        $report = TripReport::query()->with('speciesCounts.species')->sole();
        $count = $report->speciesCounts->firstWhere('species.name', 'Calico Bass');
        $this->assertSame(75, $count->count);
        $this->assertSame(50, $count->released_count);
        $this->assertSame($support->id, $count->components[0]['evidence']['corroborated_releases']['payload_id']);
        $this->assertFalse($primary->parserErrors()->whereNull('resolution_type')->exists());
    }

    public function test_reconciliation_resolves_catalog_identities_once_per_invocation(): void
    {
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, '75 Calico Bass, 17 Yellowtail'));
        [, , $parsed] = app(RawPayloadEvaluator::class)->evaluate($primary->id);
        $reports = new ParsedFishCountCollection(collect(range(1, 20))->map(fn (): ParsedTripReportData => clone $parsed->tripReports[0]));
        DB::enableQueryLog();
        try {
            app(LimitsSourceReconciler::class)->reconcile($reports, $primary);
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from ["`]*(?:boats|boat_aliases|trip_types|trip_type_aliases)["`]*\\s/i', $query['query']) === 1);
            $this->assertLessThanOrEqual(4, $queries->count());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_release_only_fallback_keeps_unknown_retained_and_explicit_releases(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $support = $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, '20 Calico Bass Released, 17 Yellowtail'));
        $action = app(ParseRawPayloadAction::class);
        $action->handleWithOptions($support->id, ParseRawPayloadOptions::maintenance());
        $action->handleWithOptions($primary->id, ParseRawPayloadOptions::maintenance());
        $count = TripReport::query()->with('speciesCounts.species')->sole()->speciesCounts->firstWhere('species.name', 'Calico Bass');
        $this->assertNull($count->count);
        $this->assertSame(20, $count->released_count);
        $this->assertSame($support->id, $count->components[0]['evidence']['corroborated_releases']['payload_id']);
        $this->assertFalse($primary->parserErrors()->whereNull('resolution_type')->exists());
    }

    public function test_conflicting_explicit_releases_preserve_both_sources_for_review(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass, 20 Calico Bass Released, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $support = $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, '75 Calico Bass, 50 Calico Bass Released, 17 Yellowtail'));
        $action = app(ParseRawPayloadAction::class);
        $action->handleWithOptions($support->id, ParseRawPayloadOptions::maintenance());
        $action->handleWithOptions($primary->id, ParseRawPayloadOptions::maintenance());
        $this->assertSame(2, TripReport::query()->count());
        $this->assertTrue($primary->parserErrors()->whereNull('resolution_type')->exists());
    }

    #[DataProvider('corroboratedLocations')]
    public function test_matching_source_location_overrides_the_duration_heuristic(string $trip, string $counts): void
    {
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $support = $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", $trip, 25, $counts));
        app(ParseRawPayloadAction::class)->handleWithOptions($primary->id, ParseRawPayloadOptions::maintenance());
        $report = TripReport::query()->sole();
        $this->assertSame('california_southern', $report->metadata['limits_jurisdiction']);
        $this->assertSame($support->id, $report->metadata['corroborated_context']['payload_id']);
    }

    public static function corroboratedLocations(): array
    {
        return [
            ['3/4 Day', '17 Yellowtail, limits of Calico Bass in California waters'],
            ['3/4 Day Local', '17 Yellowtail, limits of Calico Bass'],
        ];
    }

    public function test_contradictory_catch_locations_remain_reviewable_without_an_estimate(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $primary = $this->payload('fishermans_landing', '2026-10-07', '<p>The Dolphin caught limits of Calico Bass in Mexican waters, 17 Yellowtail on their 3/4 day trip for 25 anglers.</p>');
        $support = $this->payload('sportfishingreport_landing_pages', '2026-10-07', $this->partyRow('Dolphin', "Fisherman's Landing", '3/4 Day', 25, '17 Yellowtail, limits of Calico Bass in California waters'));
        app(ParseRawPayloadAction::class)->handleWithOptions($primary->id, ParseRawPayloadOptions::maintenance());
        $report = TripReport::query()->with('speciesCounts.species')->sole();
        $this->assertNull($report->metadata['limits_jurisdiction']);
        $this->assertSame('conflicting_catch_locations', $report->metadata['limits_source_conflict']['reason']);
        $count = $report->speciesCounts->firstWhere('species.name', 'Calico Bass');
        $this->assertNull($count->count);
        $this->assertSame('conflicting_catch_locations', $count->components[0]['evidence']['unresolved_reason']);
        $error = $primary->parserErrors()->whereNull('resolution_type')->where('error_type', 'extracted_value_source_span_mismatch')->sole();
        app(ParserDiagnosticReviewRequestValidator::class)->validate(app(ParserDiagnosticReviewRequestFactory::class)->make($primary, $error));
    }

    private function payload(string $slug, string $date, string $body): RawScrapePayload
    {
        $source = ScrapeSource::query()->where('slug', $slug)->firstOrFail();
        $run = ScrapeRun::query()->create(['scrape_source_id' => $source->id, 'run_type' => ScrapeRunType::Manual, 'target_date' => $date]);

        return RawScrapePayload::query()->create(['scrape_run_id' => $run->id, 'scrape_source_id' => $source->id, 'target_date' => $date, 'url' => 'https://example.test', 'payload' => $body, 'payload_hash' => hash('sha256', $body), 'fetched_at' => now()]);
    }

    private function partyRow(string $boat, string $landing, string $trip, int $anglers, string $counts): string
    {
        return '<div class="panel"><h2>San Diego Fish Counts</h2><div style="border-top: 1px solid #dedede"><div class="row"><div class="col-md-4"><a>'.$boat.'</a><a>'.$landing.'</a></div><div class="col-md-2">'.$anglers.' Anglers</div><div class="col-md-2">'.$trip.' Trip</div><div class="col-md-1">&nbsp;</div><div class="col-md-3">'.$counts.'</div></div></div></div>';
    }
}
