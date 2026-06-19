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
        $this->assertSame('Fisherman\'s Landing', $report->landingName);
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
        $this->assertSame('party-boat-scores', $dolphin->metadata['format']);
        $this->assertSame('fallback', $dolphin->metadata['source_role']);
        $this->assertSame('Southern Cal', $southernCal->boatName);
        $this->assertSame('Oceanside Sea Center', $southernCal->landingName);
        $this->assertSame(['Dolphin', 'Southern Cal'], $parsed->tripReports->pluck('boatName')->all());
    }
}
