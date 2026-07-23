<?php

namespace Tests\Feature;

use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\RawPayloadData;
use App\Services\Parsing\DiagnosticContextFactory;
use App\Services\Parsing\Rules\ExtractedValueSourceSpanMismatchRule;
use App\Services\Parsing\Rules\FractionalTripConflictRule;
use App\Services\Parsing\Rules\UnaccountedNumericTokensRule;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SourceAdapterFixtureTest extends TestCase
{
    public function test_landing_source_parser_handles_html_table_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-01-05'),
            url: 'https://www.fishermanslanding.com/fish-counts.php?date=2026-01-05',
            body: <<<'HTML'
                <table>
                    <tr><th>Boat</th><th>Trip</th><th>Anglers</th><th>Fish Count</th></tr>
                    <tr><td>Dolphin</td><td>Full Day</td><td>20</td><td>40 Yellowtail, 5 Calico Bass, 8 Calico Bass <span style="color:red">Released</span></td></tr>
                </table>
            HTML,
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Dolphin', $report->boatName);
        $this->assertSame('Fisherman\'s Landing', $report->landingName);
        $this->assertSame('Full Day', $report->tripTypeName);
        $this->assertSame(20, $report->anglers);
        $this->assertSame('source-specific-fishermans_landing-v5', $report->metadata['parser']);
        $this->assertSame('Yellowtail', $report->speciesCounts[0]->speciesName);
        $this->assertSame(40, $report->speciesCounts[0]->count);
        $this->assertSame('Calico Bass', $report->speciesCounts[1]->speciesName);
        $this->assertSame(5, $report->speciesCounts[1]->count);
        $this->assertSame(8, $report->speciesCounts[1]->releasedCount);
    }

    public function test_landing_source_parser_handles_hm_landing_blank_anglers_header_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'hm_landing',
            targetDate: CarbonImmutable::parse('2026-06-17'),
            url: 'https://www.fishcounts.com/hmlanding/fishcounts.php',
            body: <<<'HTML'
                <table>
                    <tr><td class="HMFishCountBreak" colspan="4">Wednesday June 17th, 2026</td></tr>
                    <tr><th>Boat</th><th>Trip Type</th><th></th><th>Fish Count</th></tr>
                    <tr><td>Premier</td><td>1/2 Day AM</td><td>32</td><td>25 Calico Bass Released, 21 Calico Bass, 9 Rockfish, 2 Sculpin, 2 Sheephead</td></tr>
                    <tr><td>5 Boats</td><td>5 Trips</td><td>103 Anglers</td><td>65 Yellowtail, 50 Calico Bass Released</td></tr>
                </table>
            HTML,
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Premier', $report->boatName);
        $this->assertSame('1/2 Day AM', $report->tripTypeName);
        $this->assertSame(32, $report->anglers);
        $this->assertSame('Calico Bass', $report->speciesCounts[0]->speciesName);
        $this->assertSame(21, $report->speciesCounts[0]->count);
        $this->assertSame(25, $report->speciesCounts[0]->releasedCount);
    }

    public function test_hm_landing_parser_only_reads_the_requested_date_section(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'hm_landing',
            targetDate: CarbonImmutable::parse('2026-07-22'),
            url: 'https://www.fishcounts.com/hmlanding/fishcounts.php',
            body: <<<'HTML'
                <!doctype html>
                <html><body><table><tbody>
                    <tr><td class="HMFishCountBreak" colspan="4">Thursday July 23rd, 2026</td></tr>
                    <tr><th>Boat</th><th>Trip Type</th><th></th><th>Fish Count</th></tr>
                    <tr><td>Patriot (SD)</td><td>2.5 Day</td><td>6</td><td>18 Bluefin Tuna</td></tr>
                    <tr><td class="HMFishCountBreak" colspan="4">Wednesday July 22nd, 2026</td></tr>
                    <tr><th>Boat</th><th>Trip Type</th><th></th><th>Fish Count</th></tr>
                    <tr><td>Premier</td><td>1/2 Day AM</td><td>37</td><td>93 Calico Bass</td></tr>
                </tbody></table></body></html>
                HTML,
        ));

        $report = $parsed->tripReports->sole();

        $this->assertSame('Premier', $report->boatName);
        $this->assertSame('2026-07-22', $report->tripDate->toDateString());
        $this->assertSame(93, $report->speciesCounts[0]->count);
        $this->assertSame('source-specific-hm_landing-v5', $parsed->parserVersion);
    }

    public function test_fishermans_landing_parser_only_reads_the_requested_date_section(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-22'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>
                    07/23/2026<br>
                    The Pegasus returned with 30 Bluefin Tuna for 19 anglers on a 3 Day trip.<br><br>
                    7/22/2026<br>
                    The Dolphin returned with 40 Yellowtail for 20 anglers on a 1/2 Day trip.<br><br>
                    7/21/2026<br>
                    The Pacific Queen returned with 25 Bluefin Tuna for 18 anglers on a Full Day trip.
                </p>
                HTML,
        ));

        $report = $parsed->tripReports->sole();

        $this->assertSame('Dolphin', $report->boatName);
        $this->assertSame('2026-07-22', $report->tripDate->toDateString());
        $this->assertSame(40, $report->speciesCounts[0]->count);
        $this->assertSame('source-specific-fishermans_landing-v5', $parsed->parserVersion);
    }

    public function test_hm_landing_parser_fails_closed_without_trusted_date_markers(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'hm_landing',
            targetDate: CarbonImmutable::parse('2026-07-22'),
            url: 'https://www.fishcounts.com/hmlanding/fishcounts.php',
            body: '<table><tr><td>Premier</td><td>1/2 Day AM</td><td>37</td><td>93 Calico Bass</td></tr></table>',
        ));

        $this->assertCount(0, $parsed->tripReports);
    }

    public function test_hm_landing_parser_fails_closed_when_the_target_date_is_absent(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'hm_landing',
            targetDate: CarbonImmutable::parse('2026-07-22'),
            url: 'https://www.fishcounts.com/hmlanding/fishcounts.php',
            body: <<<'HTML'
                <table>
                    <tr><td class="HMFishCountBreak" colspan="4">Thursday July 23rd, 2026</td></tr>
                    <tr><td>Patriot (SD)</td><td>2.5 Day</td><td>6</td><td>18 Bluefin Tuna</td></tr>
                </table>
                HTML,
        ));

        $this->assertCount(0, $parsed->tripReports);
    }

    public function test_hm_landing_diagnostics_do_not_match_partial_numeric_species_counts_to_another_row(): void
    {
        $payload = new RawPayloadData(
            sourceKey: 'hm_landing',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.fishcounts.com/hmlanding/fishcounts.php',
            body: <<<'HTML'
                <table>
                    <tr><td class="HMFishCountBreak" colspan="4">Sunday July 12th, 2026</td></tr>
                    <tr><th>Boat</th><th>Trip Type</th><th></th><th>Fish Count</th></tr>
                    <tr><td>Excalibur</td><td>3 Day</td><td>28</td><td>200 Rockfish, 115 Red Rockfish, 91 Bluefin Tuna, 17 Dorado, 17 Yellowtail, 15 Sheephead, 1 Yellowfin Tuna</td></tr>
                    <tr><td>Nautilus</td><td>1.5 Day</td><td>5</td><td>1 Bluefin Tuna</td></tr>
                </table>
            HTML,
        );
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $excalibur = $parsed->tripReports->first();
        $nautilus = $parsed->tripReports->get(1);

        $this->assertCount(2, $parsed->tripReports);
        $this->assertSame('Excalibur', $excalibur->boatName);
        $this->assertSame(
            [
                ['species' => 'Rockfish', 'retained' => 200, 'released' => 0],
                ['species' => 'Red Rockfish', 'retained' => 115, 'released' => 0],
                ['species' => 'Bluefin Tuna', 'retained' => 91, 'released' => 0],
                ['species' => 'Dorado', 'retained' => 17, 'released' => 0],
                ['species' => 'Yellowtail', 'retained' => 17, 'released' => 0],
                ['species' => 'Sheephead', 'retained' => 15, 'released' => 0],
                ['species' => 'Yellowfin Tuna', 'retained' => 1, 'released' => 0],
            ],
            collect($excalibur->speciesCounts)
                ->map(fn ($count): array => [
                    'species' => $count->speciesName,
                    'retained' => $count->count,
                    'released' => $count->releasedCount,
                ])
                ->all(),
        );
        $this->assertSame('Nautilus', $nautilus->boatName);
        $this->assertSame('H&M Landing', $nautilus->landingName);
        $this->assertSame('1.5 Day', $nautilus->tripTypeName);
        $this->assertSame(5, $nautilus->anglers);
        $this->assertSame('Bluefin Tuna', $nautilus->speciesCounts[0]->speciesName);
        $this->assertSame(1, $nautilus->speciesCounts[0]->count);
        $this->assertSame(0, $nautilus->speciesCounts[0]->releasedCount);

        $paragraph = app(DiagnosticContextFactory::class)->paragraphForReport($payload, $nautilus);
        $validationData = new ParsedReportValidationData(
            payload: $payload,
            parsed: $parsed,
            report: $nautilus,
            reportIndex: 1,
            parserVersion: $parsed->parserVersion ?? '',
            format: $parsed->format ?? '',
            sourceIdentifier: null,
            sanitizedParagraph: $paragraph,
        );

        $this->assertSame('Nautilus | 1.5 Day | 5 | 1 Bluefin Tuna |', $paragraph);
        $this->assertSame([], app(ExtractedValueSourceSpanMismatchRule::class)->inspect($validationData));
        $this->assertSame([], app(UnaccountedNumericTokensRule::class)->inspect($validationData));
    }

    public function test_landing_source_parser_handles_narrative_report_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-06-17'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The Constitution returned with 8 Bluefin Tuna (up to 80 lbs.), 74&nbsp;Yellowtail (up to 30 lbs.), 12 Bonita, 45 Vermillion red, and 30 assorted rock fish. For their 2.5 Day with 19 anglers aboard.</p>
            HTML,
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Constitution', $report->boatName);
        $this->assertSame('2.5 Day', $report->tripTypeName);
        $this->assertSame(19, $report->anglers);
        $this->assertSame('Bluefin Tuna', $report->speciesCounts[0]->speciesName);
        $this->assertSame(8, $report->speciesCounts[0]->count);
        $this->assertSame('Yellowtail', $report->speciesCounts[1]->speciesName);
        $this->assertSame(74, $report->speciesCounts[1]->count);
    }

    public function test_fishermans_landing_parser_preserves_three_quarter_day_trip_type(): void
    {
        $paragraph = 'The Sea Watch local 3/4 Day trip finished with 12 Yellowtail, 85 Barracuda and 25 Calico Bass for 28 anglers.';
        $payload = new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: "<p>{$paragraph}</p>",
        );
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('The Sea Watch local', $report->boatName);
        $this->assertSame('Fisherman\'s Landing', $report->landingName);
        $this->assertSame('3/4 Day', $report->tripTypeName);
        $this->assertSame(28, $report->anglers);
        $this->assertSame(
            [
                ['species' => 'Yellowtail', 'retained' => 12, 'released' => 0],
                ['species' => 'Barracuda', 'retained' => 85, 'released' => 0],
                ['species' => 'Calico Bass', 'retained' => 25, 'released' => 0],
            ],
            collect($report->speciesCounts)
                ->map(fn ($count): array => [
                    'species' => $count->speciesName,
                    'retained' => $count->count,
                    'released' => $count->releasedCount,
                ])
                ->all(),
        );

        $diagnostics = app(FractionalTripConflictRule::class)->inspect(new ParsedReportValidationData(
            payload: $payload,
            parsed: $parsed,
            report: $report,
            reportIndex: 0,
            parserVersion: $parsed->parserVersion ?? '',
            format: $parsed->format ?? '',
            sourceIdentifier: 'paragraph:0',
            sanitizedParagraph: $paragraph,
        ));

        $this->assertSame([], $diagnostics);
    }

    public function test_landing_source_parser_handles_called_in_narrative_without_trip_duration_noise(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The Poseidon called in with 12 Bluefin Tuna (up to 100 lbs.), 1 Yellowfin Tuna (70 lbs.), 25 Yellowtail (up to 70 lbs.), 5 Bonito, 151 Misc. Rockfish, and 71 Vermillion Rockfish for 23 anglers on a 2 day trip.</p>
                <p>The Pacific Queen returned this morning with 49 Yellowtail for 30 anglers on a 1.5 day charter.</p>
            HTML,
        ));

        $poseidon = $parsed->tripReports->first();
        $pacificQueen = $parsed->tripReports->get(1);

        $this->assertCount(2, $parsed->tripReports);
        $this->assertSame('Poseidon', $poseidon->boatName);
        $this->assertSame('2 Day', $poseidon->tripTypeName);
        $this->assertSame(23, $poseidon->anglers);
        $this->assertSame('Bluefin Tuna', $poseidon->speciesCounts[0]->speciesName);
        $this->assertSame(12, $poseidon->speciesCounts[0]->count);
        $this->assertSame('Misc Rockfish', $poseidon->speciesCounts[4]->speciesName);
        $this->assertSame(151, $poseidon->speciesCounts[4]->count);
        $this->assertSame('Vermillion Rockfish', $poseidon->speciesCounts[5]->speciesName);
        $this->assertSame(71, $poseidon->speciesCounts[5]->count);
        $this->assertSame('Pacific Queen', $pacificQueen->boatName);
        $this->assertSame(['Yellowtail'], collect($pacificQueen->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_landing_source_parser_ignores_trip_duration_text_inside_species_counts(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-06-19'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The Dolphin PM trip returned with 111 mixed Rockfish, 4 Sheephead, 3 Calico Bass, 4 Sand Bass, 6 Sculpin, and 1 Cabezon for 32 anglers.</p>
                <p>The Liberty caught 5 Yellowtail, 1 Bonito, 185 Whitefish and 22 Sculpin for 37 anglers on a Full Day trip.</p>
                <p>The Tomahawk called in with 35 Yellowtail and 1 Dorado on their 1.5 day trip for 28 anglers.</p>
            HTML,
        ));

        $dolphin = $parsed->tripReports->first();
        $liberty = $parsed->tripReports->get(1);
        $tomahawk = $parsed->tripReports->get(2);

        $this->assertCount(3, $parsed->tripReports);
        $this->assertSame('Dolphin', $dolphin->boatName);
        $this->assertSame('1/2 Day PM', $dolphin->tripTypeName);
        $this->assertSame(['Mixed Rockfish', 'Sheephead', 'Calico Bass', 'Sand Bass', 'Sculpin', 'Cabezon'], collect($dolphin->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame('Liberty', $liberty->boatName);
        $this->assertSame('Full Day', $liberty->tripTypeName);
        $this->assertSame(['Yellowtail', 'Bonito', 'Whitefish', 'Sculpin'], collect($liberty->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame('Tomahawk', $tomahawk->boatName);
        $this->assertSame(['Yellowtail', 'Dorado'], collect($tomahawk->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_landing_source_parser_ignores_day_progress_and_private_charter_text_inside_species_counts(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-05'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The <strong>Pacific Queen</strong> called in with 95 Bluefin Tuna (39 @ 100 to 220 lbs. and the rest are 60 to 90 lbs.) for 24 anglers on day 2 of their 3 day trip.</p>
                <p>The <strong>Pacific Queen</strong> called in with 63 Yellowtail, and 3 Bluefin for their 2 Day private charter for 17 angler.</p>
                <p>The <strong>Liberty</strong> called in with 15 Yellowtail and 1 Dorado for their Overnight trip for 37 anglers.</p>
                <p>The <strong>Dolphin</strong> Twilight trip returned with 80 Sandbass and 5 Sculpin for their 21 Anglers.</p>
            HTML,
        ));

        $firstPacificQueen = $parsed->tripReports->first();
        $secondPacificQueen = $parsed->tripReports->get(1);
        $liberty = $parsed->tripReports->get(2);
        $dolphin = $parsed->tripReports->get(3);

        $this->assertCount(4, $parsed->tripReports);
        $this->assertSame(['Bluefin Tuna'], collect($firstPacificQueen->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(95, $firstPacificQueen->speciesCounts[0]->count);
        $this->assertSame(['Yellowtail', 'Bluefin'], collect($secondPacificQueen->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Yellowtail', 'Dorado'], collect($liberty->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Sandbass', 'Sculpin'], collect($dolphin->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_landing_source_parser_handles_obvious_source_typos_and_weight_notes(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-04'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The <strong>Pacific Queen</strong> caught 8 Bluefin amd 82 Yellowtail for their 2 day trip with 23 anglers.</p>
                <p>The <strong>Dolphin</strong> AM trip returned with 38 Calico Bass (50 released), 28 Sand Bass (30 released), 12 Rockfish, 12 Barracuda, 3 Sheephead, 1 Halibut at 38 lbs for 20 anglers.</p>
                <p>The <strong>Dolphin</strong> PM trip caught 41 Rockfish, 4 Sculpin, 1 C alico Bass, 1 Lingcod and 6 Sandbass for 36 anglers.</p>
                <p>The <strong>Pacific Queen</strong> returned this morning with 38 Yellowtail and 17 Bleufin tuna (2 over 200 lbs and 15 over 100lbs) for their 3 day trip with 23 anglers aboard.</p>
            HTML,
        ));

        $firstPacificQueen = $parsed->tripReports->first();
        $dolphin = $parsed->tripReports->get(1);
        $dolphinPm = $parsed->tripReports->get(2);
        $secondPacificQueen = $parsed->tripReports->get(3);

        $this->assertCount(4, $parsed->tripReports);
        $this->assertSame(['Bluefin', 'Yellowtail'], collect($firstPacificQueen->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Calico Bass', 'Sand Bass', 'Rockfish', 'Barracuda', 'Sheephead', 'Halibut'], collect($dolphin->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Rockfish', 'Sculpin', 'Calico Bass', 'Lingcod', 'Sandbass'], collect($dolphinPm->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Yellowtail', 'Bluefin Tuna'], collect($secondPacificQueen->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_fishermans_landing_parser_handles_recent_production_narrative_variants(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The <strong>Dolphin</strong> PM trip had 29 Rockfish, 20 Cakico Bass (50 released), 27 Sandbass, 9 Sheephead, 5 Sculpin, and 8 Bonito for 35 anglers.</p>
                <p>The <strong>Islander</strong> called in with 105 Bluefin and 40 Yellowtail for 24 anglers on a three day trip.</p>
                <p>The <strong>Fortune</strong> called in with 44 Bluefin Tuna (up to 130 lbs.) for 17 anglers on a reverse 1.5 day trip.</p>
                <p>The <strong>Liberty</strong> just called in with 70 Bluefin Tuna (up to 200lbs) for their 2.5 day for 24 anglers.</p>
                <p>The <strong>Pegasus</strong> returned this morning with LIMITS (96) of Bluefin Tuna (up to 160 lbs.) 6 Yellowtail, 1 Yellowfin, and 1 Dorado for 16 anglers on a 3 day trip.</p>
                <p>The <strong>Constitution</strong> is returning this morning with 110 Bluefin Tuna (up to 200 lbs.) 22 Yellowtail, and 2 Dorado for 20 anglers on a 3 day trip.</p>
                <p>The <strong>Dolphin</strong> 1/2 Day AM caught 41 Rockfish, 40 Calico Bass Released, 26 Calico Bass, 7 Sand Bass, 7 Barracuda, 4 Sheephead, 4 Sculpin, and 1 Yellowtail for 54 anglers.</p>
                HTML,
        ));

        $this->assertSame(
            ['Dolphin', 'Islander', 'Fortune', 'Liberty', 'Pegasus', 'Constitution', 'The Dolphin'],
            $parsed->tripReports->pluck('boatName')->all(),
        );
        $this->assertCount(7, $parsed->tripReports);
        $this->assertSame('3 Day', $parsed->tripReports->get(1)->tripTypeName);
        $this->assertSame(['Bluefin', 'Yellowtail'], collect($parsed->tripReports->get(1)->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame('1.5 Day', $parsed->tripReports->get(2)->tripTypeName);
        $this->assertSame('2.5 Day', $parsed->tripReports->get(3)->tripTypeName);
        $this->assertSame(96, $parsed->tripReports->get(4)->speciesCounts[0]->count);
        $this->assertSame('Bluefin Tuna', $parsed->tripReports->get(4)->speciesCounts[0]->speciesName);
        $this->assertSame(['Bluefin Tuna', 'Yellowtail', 'Dorado'], collect($parsed->tripReports->get(5)->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame('1/2 Day AM', $parsed->tripReports->get(6)->tripTypeName);
        $this->assertSame('Calico Bass', $parsed->tripReports->first()->speciesCounts[1]->speciesName);
        $this->assertSame(50, $parsed->tripReports->first()->speciesCounts[1]->releasedCount);
    }

    public function test_landing_source_parser_infers_half_day_trip_type_from_am_pm_trip_narrative(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The <strong>Dolphin</strong> PM trip caught 81 Rockfish and 2 Calico Bass for 35 anglers.</p>
                <p>The <strong>Dolphin</strong> AM trip caught 78 Rockfish, 3 Whitefish, 3 Sandbass and 2 Calico Bass for 55 anglers.</p>
            HTML,
        ));

        $pmReport = $parsed->tripReports->first();
        $amReport = $parsed->tripReports->get(1);

        $this->assertCount(2, $parsed->tripReports);
        $this->assertSame('Dolphin', $pmReport->boatName);
        $this->assertSame('1/2 Day PM', $pmReport->tripTypeName);
        $this->assertSame(35, $pmReport->anglers);
        $this->assertSame('Dolphin', $amReport->boatName);
        $this->assertSame('1/2 Day AM', $amReport->tripTypeName);
        $this->assertSame(55, $amReport->anglers);
    }

    public function test_landing_source_parser_strips_schedule_and_sentence_fragments_from_boat_names(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-09'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: <<<'HTML'
                <p>The Dolphin (AM) Trip caught 32 Calico Bass and 7 Sand Bass for 22 anglers.</p>
                <p>FridayThe Dolphin Twilight trip last night returned with 18 Sand Bass for 14 anglers.</p>
                <p>Lucky B caught 10 Yellowtail for 6 anglers.</p>
                <p>The Tomahawk just called in with 35 Yellowtail for 28 anglers.</p>
                <p>Wednesday San Diego returned with 20 Yellowtail for 30 anglers.</p>
            HTML,
        ));

        $this->assertSame(
            ['Dolphin', 'Dolphin', 'Lucky B', 'Tomahawk', 'San Diego'],
            $parsed->tripReports->pluck('boatName')->all(),
        );
        $this->assertSame('1/2 Day AM', $parsed->tripReports->get(0)->tripTypeName);
        $this->assertSame('Twilight', $parsed->tripReports->get(1)->tripTypeName);
    }

    public function test_seaforth_source_parser_handles_narrative_report_without_anglers_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-06-17'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-06-17',
            body: <<<'HTML'
                <h2>Wednesday Report</h2>
                <p>Polaris Supreme 3 Day</p>
                <p>The Polaris Supreme finished up their 3 Day with 30 Bluefin Tuna from 50-70lbs, 28 Yellowtail and 85 Vermilion Rockfish.</p>
            HTML,
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Polaris Supreme', $report->boatName);
        $this->assertSame('3 Day', $report->tripTypeName);
        $this->assertNull($report->anglers);
        $this->assertSame('Seaforth Sportfishing', $report->landingName);
        $this->assertSame('narrative', $report->metadata['format']);
        $this->assertSame('Bluefin Tuna', $report->speciesCounts[0]->speciesName);
        $this->assertSame(30, $report->speciesCounts[0]->count);
        $this->assertSame('Yellowtail', $report->speciesCounts[1]->speciesName);
        $this->assertSame(28, $report->speciesCounts[1]->count);
        $this->assertSame('Vermilion Rockfish', $report->speciesCounts[2]->speciesName);
        $this->assertSame(85, $report->speciesCounts[2]->count);
    }

    public function test_seaforth_source_parser_handles_list_item_narrative_reports(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-06-18',
            body: <<<'HTML'
                <ul>
                    <li>The <em>New Seaforth</em>'s Twilight trip finished with 12 calico bass (100 released), 1 sand bass, 2 barracuda, 1 sheephead and 1 rockfish.</li>
                    <li>The <em>Tribute</em> finished up their 1.5 Day wth 10 Bluefin Tuna up to 120lbs and 4 Yellowtail!</li>
                    <li>The <em>Polaris Supreme</em> just checked in from their 3 Day wth 33 Bluefin Tuna up to 120lbs and 17 Yellowtail from 20-30lbs!</li>
                </ul>
            HTML,
        ));

        $twilight = $parsed->tripReports->first();
        $tribute = $parsed->tripReports->get(1);
        $polarisSupreme = $parsed->tripReports->get(2);

        $this->assertCount(3, $parsed->tripReports);
        $this->assertSame('New Seaforth', $twilight->boatName);
        $this->assertSame('Twilight', $twilight->tripTypeName);
        $this->assertSame('Calico Bass', $twilight->speciesCounts[0]->speciesName);
        $this->assertSame(12, $twilight->speciesCounts[0]->count);
        $this->assertSame(100, $twilight->speciesCounts[0]->releasedCount);
        $this->assertSame('Tribute', $tribute->boatName);
        $this->assertSame('1.5 Day', $tribute->tripTypeName);
        $this->assertSame(['Bluefin Tuna', 'Yellowtail'], collect($tribute->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame('Polaris Supreme', $polarisSupreme->boatName);
        $this->assertSame('3 Day', $polarisSupreme->tripTypeName);
        $this->assertSame(['Bluefin Tuna', 'Yellowtail'], collect($polarisSupreme->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_seaforth_parser_handles_recent_production_list_item_variants(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-07-12',
            body: <<<'HTML'
                <ul>
                    <li>The <em>Pacific Voyager</em> finished up their 3 Day with 69 Bluefin, 1 Yellowfin and 32 Yellowtail! 28 of the Bluefin were in the 100-160lb class.</li>
                    <li>The <em>San Diego</em> finished up their Full Day to the Coronado Islands with 52 Yellowtail for their 22 anglers.</li>
                    <li>The <em>Sea Watch</em> Coronado islands Full Day finished up with 5 Yellowtail, 5 Calico bass, 40 Rockfish and 50 Whitefish for 10 anglers.</li>
                    <li>The <em>Voyager</em> on a full day charter returned with 6 Yellowtail, 43 Barracuda, 14 Bonito, 35 Calico Bass, 3 Sheephead, 1 Rockfish, 1 Whitefish.</li>
                    <li>The <em>El Gato Dos</em> on a Full Day Offshore charter caught 12 Dorado and 17 Yellowtail for 6 anglers</li>
                    <li>The <em>Sea Watch</em> Twilight trip finished with 3 Yellowtail, 26 Calico Bass (100 released), 136 Barracuda, 21 Bonito, 10 Rockfish for 38 anglers.
                </ul>
                HTML,
        ));

        $this->assertSame(
            ['Pacific Voyager', 'San Diego', 'Sea Watch', 'Voyager', 'El Gato Dos', 'Sea Watch'],
            $parsed->tripReports->pluck('boatName')->all(),
        );
        $this->assertCount(6, $parsed->tripReports);
        $this->assertSame(['3 Day', 'Full Day', 'Full Day', 'Full Day', 'Full Day', 'Twilight'], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame(['Bluefin', 'Yellowfin', 'Yellowtail'], collect($parsed->tripReports->first()->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Dorado', 'Yellowtail'], collect($parsed->tripReports->get(4)->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(100, $parsed->tripReports->last()->speciesCounts[1]->releasedCount);
    }

    public function test_seaforth_parser_recovers_an_unclosed_final_list_item_at_end_of_document(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-07-12',
            body: '<ul><li>The <em>Sea Watch</em> Twilight trip finished with 26 Calico Bass. 100 were released.',
        ));

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Sea Watch', $parsed->tripReports->first()->boatName);
        $this->assertSame(26, $parsed->tripReports->first()->speciesCounts[0]->count);
        $this->assertSame(100, $parsed->tripReports->first()->speciesCounts[0]->releasedCount);
    }

    public function test_seaforth_source_parser_handles_returned_full_day_six_pack_report(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-07-13'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-07-13',
            body: <<<'HTML'
                <ul>
                    <li>The <em>El Gato Dos</em> returned on a full day 6 pack charter with 5 anglers and they reported 10 Dorado, and 4 Yellowtail.</li>
                    <li>The <em>Tribute</em> finished up their 1.5 Day with 9 Yellowtail.</li>
                </ul>
            HTML,
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(2, $parsed->tripReports);
        $this->assertSame('El Gato Dos', $report->boatName);
        $this->assertSame('Full Day', $report->tripTypeName);
        $this->assertSame(5, $report->anglers);
        $this->assertSame(['Dorado', 'Yellowtail'], collect($report->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame([10, 4], collect($report->speciesCounts)->pluck('count')->all());
        $this->assertSame('narrative-list-item', $report->metadata['format']);
        $this->assertSame('Tribute', $parsed->tripReports->get(1)->boatName);
        $this->assertSame(9, $parsed->tripReports->get(1)->speciesCounts[0]->count);
    }

    public function test_seaforth_source_parser_keeps_each_list_item_with_its_own_boat_and_counts(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-06-23'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-06-23',
            body: <<<'HTML'
                <ul>
                    <li>The <em>Pacific Voyager</em> returned this evening from a 2 day charter with 10 Bluefin Tuna, 11 Yellowtail, 50 Vermilion Rockfish, and 30 Calico Bass.</li>
                    <li>The <em>Apollo</em> returned from their Two Day trip with 26 Bluefin Tuna, 1 Yellowtail, 3 bonito, 2 barracuda, 25 rockfish, and 40 reds.</li>
                    <li>The <em>Polaris Supreme</em> Two Day trip finished with 54 Bluefin Tuna and 14 Yellowtail.</li>
                    <li>The <em>San Diego</em> wrapped up today's Full Day Coronado Islands trip with 32 Yellowtail.</li>
                </ul>
            HTML,
        ));

        $this->assertSame(['Pacific Voyager', 'Apollo', 'Polaris Supreme', 'San Diego'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame(['2 Day', '2 Day', '2 Day', 'Full Day Coronado Islands'], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame(10, $parsed->tripReports->get(0)->speciesCounts[0]->count);
        $this->assertSame(26, $parsed->tripReports->get(1)->speciesCounts[0]->count);
        $this->assertSame(54, $parsed->tripReports->get(2)->speciesCounts[0]->count);
    }

    public function test_seaforth_source_parser_splits_multiple_trip_reports_in_one_list_item(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-07-09'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-07-09',
            body: <<<'HTML'
                <ul>
                    <li>The <em>New Seaforth</em>'s AM Half Day trip finished with 150 Barracuda, 100 Bonito, 7 Yellowtail, 18 Whitefish, 11 Sand bass, 19 Calico bass and 1 Rockfish. Only 3 spots remain! The <em>New Seaforth</em>'s PM Half Day trip finished with 200 Barracuda, 80 Bonito, 9 Yellowtail, 3 Sand bass, 32 Calico bass and 4 rockfish.</li>
                </ul>
            HTML,
        ));

        $this->assertCount(2, $parsed->tripReports);
        $this->assertSame(['New Seaforth', 'New Seaforth'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame(['1/2 Day AM', '1/2 Day PM'], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame(150, $parsed->tripReports->get(0)->speciesCounts[0]->count);
        $this->assertSame(200, $parsed->tripReports->get(1)->speciesCounts[0]->count);
        $this->assertSame(
            ['Barracuda', 'Bonito', 'Yellowtail', 'Whitefish', 'Sand Bass', 'Calico Bass', 'Rockfish'],
            collect($parsed->tripReports->get(0)->speciesCounts)->pluck('speciesName')->all(),
        );
    }

    public function test_seaforth_source_parser_ignores_weight_and_tackle_noise_in_narrative_reports(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-06-25'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-06-25',
            body: <<<'HTML'
                <ul>
                    <li>The <em>Tribute</em> finished up their 1.5 day with 2 Bluefin, 1 Dorado and 12 Yellowtail. The Bluefin weighed up to 110 pounds and the Yellowtail were up to 35 pounds.</li>
                    <li>The <em>New Seaforth</em> finished up their 1/2 day with 164 Sand Bass. Captain Brian recommends fishing a 20-30 lb setup with a 4 oz sinker and live bait.</li>
                    <li>The <em>Sea Watch</em> finished up their 3/4 day today with 31 Yellowtail, 37 Calico Bass (90 Released), 8 Bonito, 10 Rockfish, 2 Barracuda, 3 Sculpin, and 2 Sheephead.</li>
                </ul>
            HTML,
        ));

        $tribute = $parsed->tripReports->first();
        $newSeaforth = $parsed->tripReports->get(1);
        $seaWatch = $parsed->tripReports->get(2);

        $this->assertCount(3, $parsed->tripReports);
        $this->assertSame(['Bluefin', 'Dorado', 'Yellowtail'], collect($tribute->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Sand Bass'], collect($newSeaforth->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame(['Yellowtail', 'Calico Bass', 'Bonito', 'Rockfish', 'Barracuda', 'Sculpin', 'Sheephead'], collect($seaWatch->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_seaforth_parser_handles_current_production_status_phrases(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'seaforth_landing',
            targetDate: CarbonImmutable::parse('2026-07-21'),
            url: 'https://www.seaforthlanding.com/fishcounts.php?date=2026-07-21',
            body: <<<'HTML'
                <ul>
                    <li>The <em>Pacific Voyager</em> on their 2 Day trip finished up with 36 Yellowtail.</li>
                    <li>The <em>Tribute</em> checked in from day 2 of their 3 day trip with 1 Yellowfin Tuna.</li>
                    <li>The <em>Voyager</em> arrived this afternoon from their 2 day trip with 55 Bluefin Tuna and 45 Yellowtail.</li>
                    <li>The <em>San Diego</em>'s charter group ended their full day trip to the Coronado Islands with 1 Halibut and 15 Yellowtail.</li>
                    <li>The <em>New Seaforth</em> finished their AM Half Day with 38 Yellowtail and 56 Barracuda.</li>
                    <li>The <em>New Seaforth</em> on the PM Half Day returned with 80 Calico Bass and 15 Sand Bass.</li>
                    <li>The <em>Voyager</em> on a Full day Offshore charter landed 14 Yellowfin Tuna and 1 Yellowtail for 13 anglers.</li>
                    <li>The <em>New Seaforth</em>'s Twilight trip finished with 4 Sculpin fore their 36 anglers.</li>
                    <li>The <em>Pacific Voyager</em> finished up their 2 day with 68 Bluefin Tuna with fish up to 160lbs! They also reported 7 Yellowfin Tuna and 70 Yellowtail.</li>
                </ul>
            HTML,
        ));

        $this->assertCount(9, $parsed->tripReports);
        $this->assertSame(
            ['Pacific Voyager', 'Tribute', 'Voyager', 'San Diego', 'New Seaforth', 'New Seaforth', 'Voyager', 'New Seaforth', 'Pacific Voyager'],
            $parsed->tripReports->pluck('boatName')->all(),
        );
        $this->assertSame(
            ['2 Day', '3 Day', '2 Day', 'Full Day', '1/2 Day AM', '1/2 Day PM', 'Full Day', 'Twilight', '2 Day'],
            $parsed->tripReports->pluck('tripTypeName')->all(),
        );
        $expectedReports = [
            ['anglers' => null, 'counts' => [['Yellowtail', 36]]],
            ['anglers' => null, 'counts' => [['Yellowfin Tuna', 1]]],
            ['anglers' => null, 'counts' => [['Bluefin Tuna', 55], ['Yellowtail', 45]]],
            ['anglers' => null, 'counts' => [['Halibut', 1], ['Yellowtail', 15]]],
            ['anglers' => null, 'counts' => [['Yellowtail', 38], ['Barracuda', 56]]],
            ['anglers' => null, 'counts' => [['Calico Bass', 80], ['Sand Bass', 15]]],
            ['anglers' => 13, 'counts' => [['Yellowfin Tuna', 14], ['Yellowtail', 1]]],
            ['anglers' => 36, 'counts' => [['Sculpin', 4]]],
            ['anglers' => null, 'counts' => [['Bluefin Tuna', 68], ['Yellowfin Tuna', 7], ['Yellowtail', 70]]],
        ];

        foreach ($expectedReports as $index => $expectedReport) {
            $report = $parsed->tripReports->get($index);

            $this->assertSame($expectedReport['anglers'], $report->anglers);
            $this->assertSame(
                $expectedReport['counts'],
                collect($report->speciesCounts)
                    ->map(fn (ParsedSpeciesCountData $count): array => [$count->speciesName, $count->count])
                    ->all(),
            );
        }
    }

    public function test_report_feed_source_parser_handles_json_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'sandiego_fish_reports',
            targetDate: CarbonImmutable::parse('2026-01-05'),
            url: 'https://www.sandiegofishreports.com/counts.php?date=2026-01-05',
            body: json_encode([
                'reports' => [
                    [
                        'boat_name' => 'Mission Belle',
                        'landing_name' => 'Point Loma Sportfishing',
                        'trip_type' => '3/4 Day',
                        'passengers' => 28,
                        'species_counts' => [
                            ['species' => 'Yellowtail', 'count' => 16],
                            ['species' => 'Rockfish', 'count' => 140],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Mission Belle', $report->boatName);
        $this->assertSame('Point Loma Sportfishing', $report->landingName);
        $this->assertSame('3/4 Day', $report->tripTypeName);
        $this->assertSame(28, $report->anglers);
        $this->assertSame('source-specific-sandiego_fish_reports-v4', $report->metadata['parser']);
        $this->assertSame('Rockfish', $report->speciesCounts[1]->speciesName);
        $this->assertSame(140, $report->speciesCounts[1]->count);
    }

    public function test_report_feed_source_parser_omits_json_dock_total_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'sandiego_fish_reports',
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: 'https://www.sandiegofishreports.com/dock_totals/index.php',
            body: json_encode([
                'reports' => [
                    [
                        'boat_name' => 'Dock Total',
                        'landing_name' => 'Fisherman\'s Landing',
                        'trip_type' => 'All Trips',
                        'passengers' => 115,
                        'species_counts' => [
                            ['species' => 'Rockfish', 'count' => 159],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ));

        $this->assertCount(0, $parsed->tripReports);
    }

    public function test_generic_parser_omits_explicit_dock_total_line(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'unknown_report_feed',
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: 'https://example.test/dock-total',
            body: '<p>Fisherman\'s Landing Dock Total All Trips 115 anglers 159 Rockfish, 4 Calico Bass.</p>',
        ));

        $this->assertCount(0, $parsed->tripReports);
    }

    public function test_generic_parser_ignores_trip_type_text_as_species_count(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'unknown_report_feed',
            targetDate: CarbonImmutable::parse('2026-06-23'),
            url: 'https://example.test/fish-counts',
            body: '<p>Sample Boat 3 Day Trip 10 anglers 5 Yellowtail</p>',
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame(['Yellowtail'], collect($report->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_report_feed_source_parser_omits_dock_totals_header_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'sandiego_fish_reports',
            targetDate: CarbonImmutable::parse('2026-06-17'),
            url: 'https://www.sandiegofishreports.com/dock_totals/index.php',
            body: <<<'HTML'
                <table>
                    <tr><th>Landing</th><th>Boats</th><th>Anglers</th><th>Dock Totals</th></tr>
                    <tr><td>Point Loma Sportfishing San Diego, CA</td><td>3 Boats<br>4 Trips</td><td>135 Anglers</td><td>84 Yellowtail, 34 Calico Bass, 100 Calico Bass <span style="color: red">Released</span></td></tr>
                </table>
            HTML,
        ));

        $this->assertCount(0, $parsed->tripReports);
    }

    public function test_report_feed_source_parser_omits_sportfishing_report_div_dock_totals_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'sportfishingreport_landing_pages',
            targetDate: CarbonImmutable::parse('2026-06-17'),
            url: 'https://www.sportfishingreport.com/dock_totals/?date=2026-06-17&region_id=7',
            body: <<<'HTML'
                <div style='background-color: #F3F9FD; padding: 10px; border-top: 1px solid #dedede;'>
                    <div class="row">
                        <div class="col-xs-12 col-md-4"><a href="/landings/h&m-landing.php">H&M Landing</a><br>San Diego, CA<br></div>
                        <div class="col-xs-5 col-md-2 col-md-offset-0 col-md-push-3">5 Boats / 5 Trips</div>
                        <div class="col-xs-4 col-md-2 col-md-push-3">103 Anglers</div>
                        <div class="col-xs-3 col-md-1 col-md-push-3">&nbsp;</div>
                        <div class="col-xs-12 col-md-3 col-md-offset-0 col-md-pull-5"><br>65 Yellowtail, 37 Calico Bass, 50 Calico Bass <span style='color: red'>Released</span></div>
                    </div>
                </div>
            HTML,
        ));

        $this->assertCount(0, $parsed->tripReports);
    }

    public function test_sportfishing_report_party_boat_parser_only_reads_san_diego_section(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'sportfishingreport_landing_pages',
            targetDate: CarbonImmutable::parse('2026-06-17'),
            url: 'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-17',
            body: <<<'HTML'
                <div class='panel' style='background-color: #C9E5F5'>
                    <h2 class='text-center'>Orange Fish Counts</h2>
                    <div style='background-color: #FFFFFF; padding: 10px; border-top: 1px solid #dedede;'>
                        <div class="row">
                            <div class="col-xs-12 col-md-4"><a href="/charter_boats/blackfish.php"><b>Blackfish</b></a><br><a href="/landings/daveys-locker.php">Davey's Locker</a><br>Newport Beach, CA</div>
                            <div class="col-xs-3 col-xs-offset-1 col-md-2 col-md-offset-0 col-md-push-3">4 Anglers</div>
                            <div class="col-xs-3 col-md-2 col-md-push-3">Full Day Trip</div>
                            <div class="col-xs-3 col-md-1 col-md-push-3">&nbsp;</div>
                            <div class="col-xs-11 col-xs-offset-1 col-md-3 col-md-offset-0 col-md-pull-5">25 Barracuda, 1 Yellowtail</div>
                        </div>
                    </div>
                </div>
                <div class='panel' style='background-color: #C9E5F5'>
                    <h2 class='text-center'>San Diego Fish Counts</h2>
                    <div style="padding: 10px;">
                        <div class="row">
                            <div class="col-xs-12 col-md-4"><strong>Landing</strong></div>
                            <div class="col-xs-3 col-xs-offset-1 col-md-2 col-md-offset-0 col-md-push-3"><strong>Anglers</strong></div>
                            <div class="col-xs-3 col-md-2 col-md-push-3"><strong>Trip Type</strong></div>
                            <div class="col-xs-3 col-md-1 col-md-push-3"><strong>Audio</strong></div>
                            <div class="col-xs-11 col-xs-offset-1 col-md-3 col-md-offset-0 col-md-pull-5"><strong>Fish Count</strong></div>
                        </div>
                    </div>
                    <div style='background-color: #FFFFFF; padding: 10px; border-top: 1px solid #dedede;'>
                        <div class="row">
                            <div class="col-xs-12 col-md-4"><a href="/charter_boats/dolphin.php"><b>Dolphin</b></a><br><a href="/landings/fishermans-landing.php">Fisherman's Landing</a><br>San Diego, CA</div>
                            <div class="col-xs-3 col-xs-offset-1 col-md-2 col-md-offset-0 col-md-push-3">25 Anglers</div>
                            <div class="col-xs-3 col-md-2 col-md-push-3">1/2 Day Trip</div>
                            <div class="col-xs-3 col-md-1 col-md-push-3">&nbsp;</div>
                            <div class="col-xs-11 col-xs-offset-1 col-md-3 col-md-offset-0 col-md-pull-5">19 Calico Bass, 50 Calico Bass <font color="red">Released</font></div>
                        </div>
                    </div>
                    <div style='background-color: #E0F0F9; padding: 10px; border-top: 1px solid #dedede;'>
                        <div class="row">
                            <div class="col-xs-12 col-md-4"><a target=_blank href='https://www.southerncal.net/'><img src='//media.fishreports.com/images/ani72.gif'></a> <a href="/charter_boats/southerncal.php"><b>Southern Cal</b></a><br><a href="/landings/oceanside-sea-center.php">Oceanside Sea Center</a><br>Oceanside, CA</div>
                            <div class="col-xs-3 col-xs-offset-1 col-md-2 col-md-offset-0 col-md-push-3">18 Anglers</div>
                            <div class="col-xs-3 col-md-2 col-md-push-3">1/2 Day Trip</div>
                            <div class="col-xs-3 col-md-1 col-md-push-3">&nbsp;</div>
                            <div class="col-xs-11 col-xs-offset-1 col-md-3 col-md-offset-0 col-md-pull-5">14 Calico Bass, 60 Calico Bass Released</div>
                        </div>
                    </div>
                </div>
            HTML,
        ));

        $dolphin = $parsed->tripReports->first();
        $southernCal = $parsed->tripReports->get(1);

        $this->assertCount(2, $parsed->tripReports);
        $this->assertSame('Dolphin', $dolphin->boatName);
        $this->assertSame('Fisherman\'s Landing', $dolphin->landingName);
        $this->assertSame('1/2 Day', $dolphin->tripTypeName);
        $this->assertSame(25, $dolphin->anglers);
        $this->assertSame('source-specific-sportfishingreport-party-boat-scores-v4', $dolphin->metadata['parser']);
        $this->assertSame('party-boat-scores', $dolphin->metadata['format']);
        $this->assertSame('fallback', $dolphin->metadata['source_role']);
        $this->assertSame('Southern Cal', $southernCal->boatName);
        $this->assertSame('Oceanside Sea Center', $southernCal->landingName);
        $this->assertSame(['Dolphin', 'Southern Cal'], $parsed->tripReports->pluck('boatName')->all());
    }

    public function test_sportfishing_report_party_boat_diagnostics_match_counts_to_the_correct_source_row(): void
    {
        $payload = new RawPayloadData(
            sourceKey: 'sportfishingreport_landing_pages',
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-07-12',
            body: <<<'HTML'
                <div class='panel' style='background-color: #C9E5F5'>
                    <h2 class='text-center'>San Diego Fish Counts</h2>
                    <div style='background-color: #FFFFFF; padding: 10px; border-top: 1px solid #dedede;'>
                        <div class="row">
                            <div class="col-xs-12 col-md-4"><a href="/charter_boats/constitution.php"><b>Constitution</b></a><br><a href="/landings/fishermans-landing.php">Fisherman's Landing</a><br>San Diego, CA</div>
                            <div class="col-xs-3 col-xs-offset-1 col-md-2 col-md-offset-0 col-md-push-3">20 Anglers</div>
                            <div class="col-xs-3 col-md-2 col-md-push-3">3 Day Trip</div>
                            <div class="col-xs-3 col-md-1 col-md-push-3">&nbsp;</div>
                            <div class="col-xs-11 col-xs-offset-1 col-md-3 col-md-offset-0 col-md-pull-5">110 Bluefin Tuna</div>
                        </div>
                    </div>
                    <div style='background-color: #E0F0F9; padding: 10px; border-top: 1px solid #dedede;'>
                        <div class="row">
                            <div class="col-xs-12 col-md-4"><a href="/charter_boats/sea-adventure-80.php"><b>Sea Adventure 80</b></a><br><a href="/landings/hm-landing.php">H&amp;M Landing</a><br>San Diego, CA</div>
                            <div class="col-xs-3 col-xs-offset-1 col-md-2 col-md-offset-0 col-md-push-3">27 Anglers</div>
                            <div class="col-xs-3 col-md-2 col-md-push-3">2 Day Trip</div>
                            <div class="col-xs-3 col-md-1 col-md-push-3">&nbsp;</div>
                            <div class="col-xs-11 col-xs-offset-1 col-md-3 col-md-offset-0 col-md-pull-5">10 Bluefin Tuna</div>
                        </div>
                    </div>
                </div>
            HTML,
        );
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $constitution = $parsed->tripReports->first();
        $seaAdventure = $parsed->tripReports->get(1);

        $this->assertCount(2, $parsed->tripReports);
        $this->assertSame('Constitution', $constitution->boatName);
        $this->assertSame(110, $constitution->speciesCounts[0]->count);
        $this->assertSame('Sea Adventure 80', $seaAdventure->boatName);
        $this->assertSame('H&M Landing', $seaAdventure->landingName);
        $this->assertSame('2 Day', $seaAdventure->tripTypeName);
        $this->assertSame(27, $seaAdventure->anglers);
        $this->assertSame('Bluefin Tuna', $seaAdventure->speciesCounts[0]->speciesName);
        $this->assertSame(10, $seaAdventure->speciesCounts[0]->count);
        $this->assertSame(0, $seaAdventure->speciesCounts[0]->releasedCount);

        $paragraph = app(DiagnosticContextFactory::class)->paragraphForReport($payload, $seaAdventure);
        $diagnostics = app(ExtractedValueSourceSpanMismatchRule::class)->inspect(new ParsedReportValidationData(
            payload: $payload,
            parsed: $parsed,
            report: $seaAdventure,
            reportIndex: 28,
            parserVersion: $parsed->parserVersion ?? '',
            format: $parsed->format ?? '',
            sourceIdentifier: null,
            sanitizedParagraph: $paragraph,
        ));

        $this->assertSame('10 Bluefin Tuna |', $paragraph);
        $this->assertSame([], $diagnostics);
    }
}
