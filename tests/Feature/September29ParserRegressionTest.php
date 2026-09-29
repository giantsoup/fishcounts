<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticType;
use App\Models\RawScrapePayload;
use App\Services\Parsing\AiParserDocumentSanitizer;
use App\Services\Parsing\DiagnosticContextFactory;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class September29ParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_turned_in_is_a_boat_action_and_preserves_source_spelling(): void
    {
        $payload = $this->payload('fishermans_landing', '<p>The Pacific Dawn turned in limits (85) of yelloowfin tuna and 12 dorado for 17 anglers on a 1.5 day trip</p>');
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $report = $parsed->tripReports->sole();
        $this->assertSame(['Pacific Dawn', '1.5 Day', 17], [$report->boatName, $report->tripTypeName, $report->anglers]);
        $this->assertSame(['Yelloowfin Tuna' => [85, 0], 'Dorado' => [12, 0]], $this->counts($report));
        $this->assertSame(strip_tags($payload->body), $report->rawFishCountText);
        $this->assertSame([], $this->diagnostics($payload, $parsed));
    }

    #[DataProvider('catchLineBreaks')]
    public function test_wrapped_dolphin_catches_stay_with_their_boat(string $break): void
    {
        $payload = $this->payload('fishermans_landing', <<<HTML
            <p>9/25/2026<br />
            The <strong>Islander</strong> on a 2 day had no report.<br />
            <br />
            The <strong>Dolphin</strong> &nbsp;<strong>PM</strong>&nbsp;trip returned with 17 sheephead, 68 bonito, 79 calico bass, (50 released), 4 sand bass, 28 rockfish, 4 whitefish, 23 sculpin,{$break}1&nbsp;cabazon and 6 yellowtail up to 25 pounds for 29 anglers<br />
            <br />
            The Lucky B caught Limits (30) Yellowfin (fishing in US), 2 Dorado and 1 Striped Marlin for 3 anglers on their Full day trip.<br />
            9/24/2026<br />
            The Dolphin AM trip caught 10 Rockfish for 20 anglers.</p>
            HTML);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(['Dolphin', 'Lucky B'], $parsed->tripReports->pluck('boatName')->all());
        $report = $parsed->tripReports->first();
        $this->assertSame(['1/2 Day PM', 29], [$report->tripTypeName, $report->anglers]);
        $this->assertSame(['Sheephead' => [17, 0], 'Bonito' => [68, 0], 'Calico Bass' => [79, 50], 'Sand Bass' => [4, 0], 'Rockfish' => [28, 0], 'Whitefish' => [4, 0], 'Sculpin' => [23, 0], 'Cabazon' => [1, 0], 'Yellowtail' => [6, 0]], $this->counts($report));
        $this->assertStringContainsString('79 calico bass, (50 released)', $report->rawFishCountText);
        $this->assertSame(['Yellowfin' => [30, 0], 'Dorado' => [2, 0], 'Striped Marlin' => [1, 0]], $this->counts($parsed->tripReports->last()));
        $this->assertSame([], $this->diagnostics($payload, $parsed));
    }

    /** @return array<string, array{string}> */
    public static function catchLineBreaks(): array
    {
        return ['source break' => ["<br />\n"], 'HTML break only' => ['<br />'], 'formatting newline' => ["\n"], 'two formatting lines' => ["\n\n"]];
    }

    public function test_ai_party_boat_rows_keep_identity_and_still_detect_an_omitted_trip(): void
    {
        $payload = $this->payload('sportfishingreport_landing_pages', '<div class="panel"><h2>San Diego Fish Counts</h2>'
            .$this->partyBoatRow('Blue Horizon', '2 Dorado, 8 Skipjack Tuna, 30 Yellowfin Tuna')
            .$this->partyBoatRow('Sea Star', '2 Dorado, 8 Skipjack Tuna, 30 Yellowfin Tuna').'</div>');
        $evidence = explode("\n", app(AiParserDocumentSanitizer::class)->sanitize($payload));
        $reports = collect(['Blue Horizon', 'Sea Star'])->map(fn (string $boat, int $index): ParsedTripReportData => $this->aiReport($payload, $boat, '3/4 Day', preg_replace('/^\[block:\d+\] /', '', $evidence[$index]), ['Dorado' => [2, 0], 'Skipjack Tuna' => [8, 0], 'Yellowfin Tuna' => [30, 0]], 8, 'Oceanside Sea Center'));
        $parsed = new ParsedFishCountCollection($reports, 'ai-primary-v5', 'ai-structured-output');
        $this->assertStringNotContainsString('Â', implode(' ', $evidence));
        $this->assertSame([], $this->diagnostics($payload, $parsed));
        foreach ($reports as $report) {
            $paragraph = app(DiagnosticContextFactory::class)->paragraphForReport($payload, $report);
            $this->assertStringContainsString($report->boatName, $paragraph);
            $this->assertStringContainsString('Oceanside Sea Center', $paragraph);
        }
        $missing = $this->diagnostics($payload, new ParsedFishCountCollection($reports->take(1), $parsed->parserVersion, $parsed->format));
        $this->assertCount(1, $missing);
        $this->assertSame(ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet, $missing[0]->type);
        $this->assertStringContainsString('Sea Star', $missing[0]->context['sanitized_paragraph']);
    }

    public function test_ai_sanitization_preserves_utf8_in_scoped_html(): void
    {
        $payload = $this->payload('sportfishingreport_landing_pages', '<!doctype html><html><head><meta charset="utf-8"></head><body><div class="panel"><h2>San Diego Fish Counts</h2>'
            .$this->partyBoatRow('Blue Horizon', '30 Yellowfin Tuna (30–40 pounds)').'</div></body></html>');
        $sanitized = app(AiParserDocumentSanitizer::class)->sanitize($payload);
        $this->assertStringContainsString('30 Yellowfin Tuna (30–40 pounds)', $sanitized);
        $this->assertStringNotContainsString('Â', $sanitized);
    }

    public function test_ai_shared_list_item_uses_complete_separate_trip_evidence(): void
    {
        $am = 'The New Seaforth Am trip finished up with 2 Yellowfin, 1 Dorado, 1 Yellowtail, 8 Bonito, 1 Barracuda, 33 Calico bass, 10 Sand bass, 1 Rockfish, 5 Whitefish, 3 Sheephead and 2 Bullet tuna .';
        $pm = 'The New Seaforth Pm finished up with 100 Bonito, 60 Calico bass, 6 Sand bass, 8 Rockfish, 9 Whitefish, 16 Sheephead, 2 Sculpin, and 1 Bullet tuna.';
        $advice = 'A 20–30 lb live-bait setup with #4 through 2/0 hooks is recommended.';
        $body = str_replace('New Seaforth', '<em>New Seaforth</em>', "<h4>New Seaforth Half Day</h4><ul><li>{$am} {$pm} {$advice}</li></ul>");
        $payload = $this->payload('seaforth_landing', $body);
        $reports = collect([
            $this->aiReport($payload, 'New Seaforth', '1/2 Day AM', $am, ['Yellowfin' => [2, 0], 'Dorado' => [1, 0], 'Yellowtail' => [1, 0], 'Bonito' => [8, 0], 'Barracuda' => [1, 0], 'Calico Bass' => [33, 0], 'Sand Bass' => [10, 0], 'Rockfish' => [1, 0], 'Whitefish' => [5, 0], 'Sheephead' => [3, 0], 'Bullet Tuna' => [2, 0]]),
            $this->aiReport($payload, 'New Seaforth', '1/2 Day PM', $pm, ['Bonito' => [100, 0], 'Calico Bass' => [60, 0], 'Sand Bass' => [6, 0], 'Rockfish' => [8, 0], 'Whitefish' => [9, 0], 'Sheephead' => [16, 0], 'Sculpin' => [2, 0], 'Bullet Tuna' => [1, 0]]),
        ]);
        $this->assertSame([], $this->diagnostics($payload, new ParsedFishCountCollection($reports, 'ai-primary-v5', 'ai-structured-output')));
        $badCounts = $this->counts($reports->first());
        $badCounts['Calico Bass'] = [32, 0];
        $reports[0] = $this->aiReport($payload, 'New Seaforth', '1/2 Day AM', $am, $badCounts);
        $diagnostics = $this->diagnostics($payload, new ParsedFishCountCollection($reports, 'ai-primary-v5', 'ai-structured-output'));
        $this->assertTrue(collect($diagnostics)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::ExtractedValueSourceSpanMismatch));
    }

    public function test_deterministic_replay_preserves_period_only_and_unspecified_duration_reports(): void
    {
        $payload = $this->payload('seaforth_landing', <<<'HTML'
            <h4>New Seaforth Half Day</h4><ul><li>
            The <em>New Seaforth</em> Am trip finished up with 2 Yellowfin, 1 Dorado, 1 Yellowtail, 8 Bonito, 1 Barracuda, 33 Calico bass, 10 Sand bass, 1 Rockfish, 5 Whitefish, 3 Sheephead and 2 Bullet tuna . The <em>New Seaforth</em> Pm finished up with 100 Bonito, 60 Calico bass, 6 Sand bass, 8 Rockfish, 9 Whitefish, 16 Sheephead, 2 Sculpin, and 1 Bullet tuna.
            </li></ul><h4>Thursday San Diego Full Day</h4><ul><li>
            The <em>San Diego</em> finished up their trip with 1 Wahoo, 260 Yellowfin Tuna and 12 Dorado. Fly-lined live bait on 15–30 lb tackle has been getting it done.
            </li></ul>
            HTML);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(['New Seaforth', 'New Seaforth', 'San Diego'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame(['1/2 Day AM', '1/2 Day PM', null], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame(['Yellowfin' => [2, 0], 'Dorado' => [1, 0], 'Yellowtail' => [1, 0], 'Bonito' => [8, 0], 'Barracuda' => [1, 0], 'Calico Bass' => [33, 0], 'Sand Bass' => [10, 0], 'Rockfish' => [1, 0], 'Whitefish' => [5, 0], 'Sheephead' => [3, 0], 'Bullet Tuna' => [2, 0]], $this->counts($parsed->tripReports[0]));
        $this->assertSame(['Bonito' => [100, 0], 'Calico Bass' => [60, 0], 'Sand Bass' => [6, 0], 'Rockfish' => [8, 0], 'Whitefish' => [9, 0], 'Sheephead' => [16, 0], 'Sculpin' => [2, 0], 'Bullet Tuna' => [1, 0]], $this->counts($parsed->tripReports[1]));
        $this->assertSame(['Wahoo' => [1, 0], 'Yellowfin Tuna' => [260, 0], 'Dorado' => [12, 0]], $this->counts($parsed->tripReports[2]));
        $this->assertSame([], $this->diagnostics($payload, $parsed));
    }

    public function test_fishermans_date_section_includes_following_report_paragraphs_only(): void
    {
        $payload = $this->payload('fishermans_landing', <<<'HTML'
            <div>
            <p>9/25/2026<br />
            The Lucky B caught Limits (30) Yellowfin (fishing in US), 2 Dorado and 1 Striped Marlin for 3 anglers on their Full day trip.</p>
            <p>The <strong>Tomahawk</strong> returned this morning with 95 Yellowfin Tuna, 8 Dorado, 5 Yellowtail and 3 Bluefin Tuna ( 1 @ 214 lbs. and 2 @ 150 lbs.) for 18 anglers on a 2.5 day trip.&nbsp;</p>
            <p>9/24/2026<br />
            The Dolphin AM trip caught 10 Rockfish for 20 anglers.</p>
            <p>The Liberty returned with 10 Yellowtail for 12 anglers.</p>
            </div>
            HTML);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(['Lucky B', 'Tomahawk'], $parsed->tripReports->pluck('boatName')->all());
        $report = $parsed->tripReports->last();
        $this->assertSame(['2.5 Day', 18], [$report->tripTypeName, $report->anglers]);
        $this->assertSame(['Yellowfin Tuna' => [95, 0], 'Dorado' => [8, 0], 'Yellowtail' => [5, 0], 'Bluefin Tuna' => [3, 0]], $this->counts($report));
        $this->assertSame([], $this->diagnostics($payload, $parsed));
    }

    public function test_ai_excerpt_does_not_hide_following_releases_or_a_missing_same_boat_trip(): void
    {
        $am = 'The New Seaforth AM trip caught 10 Calico Bass for 20 anglers.';
        $releases = '5 Calico Bass Released.';
        $pm = 'The New Seaforth PM trip caught 8 Bonito for 12 anglers.';
        $payload = $this->payload('seaforth_landing', "<ul><li>{$am} {$releases} {$pm}</li></ul>");
        $report = $this->aiReport($payload, 'New Seaforth', '1/2 Day AM', $am, ['Calico Bass' => [10, 0]], 20);
        $paragraph = app(DiagnosticContextFactory::class)->paragraphForReport($payload, $report);
        $this->assertSame("{$am} {$releases}", $paragraph);
        $diagnostics = $this->diagnostics($payload, new ParsedFishCountCollection(collect([$report]), 'ai-primary-v5', 'ai-structured-output'));
        $this->assertTrue(collect($diagnostics)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::ExtractedValueSourceSpanMismatch));
        $this->assertTrue(collect($diagnostics)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet && $diagnostic->context['sanitized_paragraph'] === $pm));
    }

    private function payload(string $source, string $body): RawPayloadData
    {
        return new RawPayloadData($source, CarbonImmutable::parse('2026-09-25'), 'https://example.test/counts', $body);
    }

    /** @param array<string, array{int, int}> $counts */
    private function aiReport(RawPayloadData $payload, string $boat, string $trip, string $raw, array $counts, ?int $anglers = null, string $landing = 'Seaforth Sportfishing'): ParsedTripReportData
    {
        return new ParsedTripReportData(sourceKey: $payload->sourceKey, tripDate: $payload->targetDate, regionName: 'San Diego', landingName: $landing, boatName: $boat, tripTypeName: $trip, anglers: $anglers, rawFishCountText: $raw,
            speciesCounts: collect($counts)->map(fn (array $count, string $species): ParsedSpeciesCountData => new ParsedSpeciesCountData($species, $count[0], $count[1]))->values()->all(), metadata: ['format' => 'ai-structured-output']);
    }

    private function partyBoatRow(string $boat, string $counts): string
    {
        return <<<HTML
            <div style='background-color: #F3F9FD; padding: 10px; border-top: 1px solid #dedede;'>
                <div class="row">
                    <div class="col-xs-12 col-md-4"><a href="/boat"><b>{$boat}</b></a><br>
                        <a href="/landing">Oceanside Sea Center</a><br>Oceanside, CA<br class="visible-xs-block visible-sm-block"><br class="visible-xs-block visible-sm-block"></div>
                    <div class="col-xs-3 col-md-2">8 Anglers</div>
                    <div class="col-xs-3 col-md-2">3/4 Day Trip</div>
                    <div class="col-xs-3 col-md-1">&nbsp;</div>
                    <div class="col-xs-11 col-md-3">{$counts}</div>
                </div>
            </div>
            HTML;
    }

    /** @return array<string, array{int, int}> */
    private function counts(ParsedTripReportData $report): array
    {
        return collect($report->speciesCounts)->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => [$count->count, $count->releasedCount]])->all();
    }

    /** @return array<int, ParserDiagnosticData> */
    private function diagnostics(RawPayloadData $payload, ParsedFishCountCollection $parsed): array
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);

        return collect(app(ParsedReportValidator::class)->validate(new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]), $payload, $parsed))
            ->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)->values()->all();
    }
}
