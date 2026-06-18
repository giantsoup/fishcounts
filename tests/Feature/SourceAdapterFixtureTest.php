<?php

namespace Tests\Feature;

use App\DTOs\RawPayloadData;
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
        $this->assertSame('Full Day', $report->tripTypeName);
        $this->assertSame(20, $report->anglers);
        $this->assertSame('source-specific-fishermans_landing-v1', $report->metadata['parser']);
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
        $this->assertSame('source-specific-sandiego_fish_reports-v1', $report->metadata['parser']);
        $this->assertSame('Rockfish', $report->speciesCounts[1]->speciesName);
        $this->assertSame(140, $report->speciesCounts[1]->count);
    }

    public function test_report_feed_source_parser_handles_dock_totals_header_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'sandiego_fish_reports',
            targetDate: CarbonImmutable::parse('2026-06-17'),
            url: 'https://www.sandiegofishreports.com/dock_totals/index.php',
            body: <<<'HTML'
                <table>
                    <tr><th>Landing</th><th>Boats</th><th>Anglers</th><th>Dock Totals</th></tr>
                    <tr><td>Point Loma Sportfishing</td><td>3 Boats<br>4 Trips</td><td>135 Anglers</td><td>84 Yellowtail, 34 Calico Bass, 100 Calico Bass <span style="color: red">Released</span></td></tr>
                </table>
            HTML,
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Point Loma Sportfishing', $report->landingName);
        $this->assertSame(135, $report->anglers);
        $this->assertSame('Yellowtail', $report->speciesCounts[0]->speciesName);
        $this->assertSame(84, $report->speciesCounts[0]->count);
        $this->assertSame('Calico Bass', $report->speciesCounts[1]->speciesName);
        $this->assertSame(34, $report->speciesCounts[1]->count);
        $this->assertSame(100, $report->speciesCounts[1]->releasedCount);
    }

    public function test_report_feed_source_parser_handles_sportfishing_report_div_dock_totals_fixture(): void
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

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('H&M Landing', $report->landingName);
        $this->assertSame(103, $report->anglers);
        $this->assertSame('Yellowtail', $report->speciesCounts[0]->speciesName);
        $this->assertSame(65, $report->speciesCounts[0]->count);
        $this->assertSame('Calico Bass', $report->speciesCounts[1]->speciesName);
        $this->assertSame(37, $report->speciesCounts[1]->count);
        $this->assertSame(50, $report->speciesCounts[1]->releasedCount);
    }

    public function test_report_feed_source_parser_handles_tuna_976_landing_card_fixture(): void
    {
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'tuna_976_reports',
            targetDate: CarbonImmutable::parse('2026-06-15'),
            url: 'https://www.976-tuna.com/counts?m=6&d=15&y=2026',
            body: <<<'HTML'
                <div class="row card m-2 mb-3">
                    <div class="container">
                        <div class="row mt-2 mb-2">
                            <div class="col-sm-12 col-md-12 text-center">
                                <b><a href="/landing/12/long-beach-sportfishing/counts?m=6&y=2026">Long Beach Sportfishing</a></b>
                                <div class="col-12">
                                    2 trips
                                    with
                                    48 anglers caught: 586
                                    Total Fish -
                                    162 perch,
                                    131 rockfish,
                                    126 sculpin,
                                    60 calico bass,
                                    54 whitefish,
                                    36 sheephead,
                                    12 barracuda,
                                    and
                                    5 sand bass.
                                    <br>
                                    <a class="button" href="#news_popup-12">News Reports</a>
                                    <div id="news_popup-12" class="overlay">
                                        <div class="content">
                                            <a href="/news/250917">Daily Double 1/2 Day AM Wrap-Up Fish Report</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            HTML,
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('Long Beach Sportfishing', $report->landingName);
        $this->assertSame('Southern California', $report->regionName);
        $this->assertSame(48, $report->anglers);
        $this->assertSame('landing-card', $report->metadata['format']);
        $this->assertSame('Perch', $report->speciesCounts[0]->speciesName);
        $this->assertSame(162, $report->speciesCounts[0]->count);
        $this->assertSame('Rockfish', $report->speciesCounts[1]->speciesName);
        $this->assertSame(131, $report->speciesCounts[1]->count);
        $this->assertSame('Sand Bass', $report->speciesCounts[7]->speciesName);
        $this->assertSame(5, $report->speciesCounts[7]->count);
    }
}
