<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticType;
use App\Enums\ScrapeRunType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\TripType;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenParserIssueRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{
     *     boat: string,
     *     landing: string,
     *     trip_type: ?string,
     *     anglers: ?int,
     *     species: array<int, array{name: string, retained: int, released: int}>
     * }  $expectedReport
     */
    #[DataProvider('parserIssueCases')]
    public function test_open_parser_issue_reproductions(
        string $signature,
        string $sourceKey,
        string $body,
        array $expectedReport,
        ParserDiagnosticType $diagnosticType,
        string $diagnosticField,
    ): void {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);

        $payload = new RawPayloadData(
            sourceKey: $sourceKey,
            targetDate: CarbonImmutable::parse('2026-08-24'),
            url: $this->sourceUrl($sourceKey),
            body: $body,
        );
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $report = $parsed->tripReports->first(
            fn (ParsedTripReportData $candidate): bool => $candidate->boatName === $expectedReport['boat'],
        );

        $this->assertNotNull(
            $report,
            "Parser issue {$signature} did not produce the expected boat. Actual reports: ".json_encode(
                $parsed->tripReports->map(fn (ParsedTripReportData $candidate): array => $this->reportSnapshot($candidate))->all(),
                JSON_THROW_ON_ERROR,
            ),
        );
        $this->assertSame($expectedReport, $this->reportSnapshot($report), "Parser issue {$signature} produced the wrong report.");
        $this->assertSame($parsed->parserVersion, $report->metadata['parser'] ?? null);
        $reportIndex = $parsed->tripReports->search(
            fn (ParsedTripReportData $candidate): bool => $candidate === $report,
        );
        $this->assertIsInt($reportIndex);

        $diagnostics = $this->diagnostics($payload, $parsed, $expectedReport);
        $hasReportedDiagnostic = collect($diagnostics)->contains(
            fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === $diagnosticType
                && $diagnostic->field === $diagnosticField
                && $diagnostic->context['report_index'] === $reportIndex,
        );

        $this->assertFalse($hasReportedDiagnostic, "Parser issue {$signature} still emits its reported diagnostic.");
    }

    /**
     * @return array<string, array{
     *     string,
     *     string,
     *     string,
     *     array{
     *         boat: string,
     *         landing: string,
     *         trip_type: ?string,
     *         anglers: ?int,
     *         species: array<int, array{name: string, retained: int, released: int}>
     *     },
     *     ParserDiagnosticType,
     *     string
     * }>
     */
    public static function parserIssueCases(): array
    {
        return [
            'issue 10 c8ff990d41aaf7e2528bfafae202bee5b4603232c57f56a726e89499e3b01148' => [
                'c8ff990d41aaf7e2528bfafae202bee5b4603232c57f56a726e89499e3b01148',
                'seaforth_landing',
                self::seaforthBody('The San Diego ventured down to the Coronado Islands today and bagged 108 Yellowtail for 36 anglers! Yo-yo jigs have been the hot ticket but good to always have a 25-30lb live bait setup or surface iron rod. Make those reservations the San Diego has openings the first week of September!'),
                self::report('San Diego', 'Seaforth Sportfishing', null, 36, [['Yellowtail', 108, 0]]),
                ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet,
                'report',
            ],
            'issue 11 325115f0021f9c0c1e4e1c242bd5ece956ca3b0275c5d1d68d06245dc3999d90' => [
                '325115f0021f9c0c1e4e1c242bd5ece956ca3b0275c5d1d68d06245dc3999d90',
                'fishermans_landing',
                '<p>The Dolphin PM trip caught 67 Rockfish, 7 Sculpin, 1 Sandbass, 1 Calico Bass, 1 Halibut abd 1 Yellowtail for 53 anglers.</p>',
                self::report('Dolphin', "Fisherman's Landing", '1/2 Day PM', 53, [
                    ['Rockfish', 67, 0], ['Sculpin', 7, 0], ['Sandbass', 1, 0], ['Calico Bass', 1, 0], ['Halibut', 1, 0], ['Yellowtail', 1, 0],
                ]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 12 14490980141b659ea8b42ed8471407f336571ae6bca548347d030687aed20280' => [
                '14490980141b659ea8b42ed8471407f336571ae6bca548347d030687aed20280',
                'fishermans_landing',
                '<p>The Lucky B captured 25 Yellowfin Tuna, and 2 Yellowtail for 5 anglers.</p>',
                self::report('Lucky B', "Fisherman's Landing", null, 5, [['Yellowfin Tuna', 25, 0], ['Yellowtail', 2, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'boat',
            ],
            'issue 13 28690868803b30844e2bc176032dba8261d208c7dd3027780e2200fedb6eaf63' => [
                '28690868803b30844e2bc176032dba8261d208c7dd3027780e2200fedb6eaf63',
                'fishermans_landing',
                '<p>The Fortune called in with 115 Yellowfin Tuna, 4 Dorado, 6 Yellowtail so far on their 2 day trip for 17 anglers.</p>',
                self::report('Fortune', "Fisherman's Landing", '2 Day', 17, [['Yellowfin Tuna', 115, 0], ['Dorado', 4, 0], ['Yellowtail', 6, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 14 de5e629a575642447dde8f4da0129fdeed4be3ab010746c0f99acb27b8c6ff6b' => [
                'de5e629a575642447dde8f4da0129fdeed4be3ab010746c0f99acb27b8c6ff6b',
                'fishermans_landing',
                '<p>The Pacific Dawns is returning with LIMITS (78) of Bluefin Tuna, 2 Yellowfin Tuna, 2 Dorado, 12 Yellowtail, and 1 Striped Marlin for their reversed 2.5 day trip with 13 anglers.</p>',
                self::report('Pacific Dawns', "Fisherman's Landing", '2.5 Day', 13, [
                    ['Bluefin Tuna', 78, 0], ['Yellowfin Tuna', 2, 0], ['Dorado', 2, 0], ['Yellowtail', 12, 0], ['Striped Marlin', 1, 0],
                ]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 16 43a1279ec8d651c4d3bbd5696dfcd0460f0897308b46323a4d75237b9035df7d' => [
                '43a1279ec8d651c4d3bbd5696dfcd0460f0897308b46323a4d75237b9035df7d',
                'fishermans_landing',
                '<p>The Pacific Queen called in with LIMITS (36) of Bluefin Tuna for day one of a three day trip for 18 anglers.</p>',
                self::report('Pacific Queen', "Fisherman's Landing", '3 Day', 18, [['Bluefin Tuna', 36, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 17 42357e4636bf1a784c8fba01a05047e2cd8b6db176f08aee3c75696b712e2b97' => [
                '42357e4636bf1a784c8fba01a05047e2cd8b6db176f08aee3c75696b712e2b97',
                'fishermans_landing',
                '<p>The Constitution checked in with 31 Yellowtail and 1 Bluefin Tuna do far on their 3 day trip with 20 anglers.</p>',
                self::report('Constitution', "Fisherman's Landing", '3 Day', 20, [['Yellowtail', 31, 0], ['Bluefin Tuna', 1, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 18 b9da2b2e485b8598dd35c6d7eb34ff9467a92bd68505b651ede362341e44bd6e' => [
                'b9da2b2e485b8598dd35c6d7eb34ff9467a92bd68505b651ede362341e44bd6e',
                'seaforth_landing',
                self::seaforthBody('The El Gato Dos on a full day charter had 18 quality yellowtail for their 4 anglers.'),
                self::report('El Gato Dos', 'Seaforth Sportfishing', 'Full Day', 4, [['Yellowtail', 18, 0]]),
                ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet,
                'report',
            ],
            'issue 19 6e040b4b8ea80e1d248e29c003de0b8ff5ab4ec81b1d027445077da28071dfa8' => [
                '6e040b4b8ea80e1d248e29c003de0b8ff5ab4ec81b1d027445077da28071dfa8',
                'fishermans_landing',
                '<p>The Constitution returened this morning with 36 Yellowtail, 14 Dorado, and 1 Bluefin Tuna for their 3 day trip with 20 anglers.</p>',
                self::report('Constitution', "Fisherman's Landing", '3 Day', 20, [['Yellowtail', 36, 0], ['Dorado', 14, 0], ['Bluefin Tuna', 1, 0]]),
                ParserDiagnosticType::ProseCapturedAsEntity,
                'boat',
            ],
            'issue 21 bc517c12282cb7aed13253d4ac2f877ff80fb7b074be02b5d46b6a5d330da42f' => [
                'bc517c12282cb7aed13253d4ac2f877ff80fb7b074be02b5d46b6a5d330da42f',
                'fishermans_landing',
                '<p>The Constitutioin just called in with LIMITS (28) of Bluefin Tuna for day one of their 3 day charter with 14 anglers.</p>',
                self::report('Constitutioin', "Fisherman's Landing", '3 Day', 14, [['Bluefin Tuna', 28, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 23 b64e901d9a17f596fe44064905b7c8089ddf5d93199b3206e5aabc926b168d4f' => [
                'b64e901d9a17f596fe44064905b7c8089ddf5d93199b3206e5aabc926b168d4f',
                'seaforth_landing',
                self::seaforthBody('The Tribute reported in from their 1.5 Day with 75 quality yellowtail for their 19 anglers.'),
                self::report('Tribute', 'Seaforth Sportfishing', '1.5 Day', 19, [['Yellowtail', 75, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'boat',
            ],
            'issue 24 b3888eb455f8fb6e0f63cd8ca5d53e2d66d0123abf88f1eb316d58ba7c52c767' => [
                'b3888eb455f8fb6e0f63cd8ca5d53e2d66d0123abf88f1eb316d58ba7c52c767',
                'seaforth_landing',
                self::seaforthBody('The Pacifica checked in from their 2 Day with 80 Dorado to start the trip.'),
                self::report('Pacifica', 'Seaforth Sportfishing', '2 Day', null, [['Dorado', 80, 0]]),
                ParserDiagnosticType::ProseCapturedAsEntity,
                'species',
            ],
            'issue 27 6e8e2d5bbfec1070586266dfbdb13cb79bd812b4f31813e976ff00953d8b850d' => [
                '6e8e2d5bbfec1070586266dfbdb13cb79bd812b4f31813e976ff00953d8b850d',
                'fishermans_landing',
                self::dolphinFullDayBody(),
                self::dolphinFullDayReport(),
                ParserDiagnosticType::UnknownAlias,
                'boat',
            ],
            'issue 28 1c086b04fe0c373e754bd47c2d173ca02772b63433ba7fcb8cae546121937ecb' => [
                '1c086b04fe0c373e754bd47c2d173ca02772b63433ba7fcb8cae546121937ecb',
                'fishermans_landing',
                self::dolphinFullDayBody(),
                self::dolphinFullDayReport(),
                ParserDiagnosticType::UnknownAlias,
                'trip_type',
            ],
            'issue 30 856ef91395a837529357801e0a57c15ec18da387f3b28e15700d2fd4908ecac4' => [
                '856ef91395a837529357801e0a57c15ec18da387f3b28e15700d2fd4908ecac4',
                'seaforth_landing',
                self::seaforthBody('The Voyager with 7 anglers on a two finished up with limits of Bluefin(28), 3 Dorado, 1 Yellowfin and 3 Skip jack.'),
                self::report('Voyager', 'Seaforth Sportfishing', null, 7, [['Bluefin', 28, 0], ['Dorado', 3, 0], ['Yellowfin', 1, 0], ['Skip Jack', 3, 0]]),
                ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet,
                'report',
            ],
            'issue 31 9de6b0461f2c2ad3ac31c5029c920d3148eff156c635351c5bb834b85e7ddb1b' => [
                '9de6b0461f2c2ad3ac31c5029c920d3148eff156c635351c5bb834b85e7ddb1b',
                'fishermans_landing',
                '<p>The Fortune called in with 10 Yellowtail so far still fishing for 18 anglers on their 1.5 day charter.</p>',
                self::report('Fortune', "Fisherman's Landing", '1.5 Day', 18, [['Yellowtail', 10, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 32 e8ee638cef94f98e737b17b9071a45e9e7581efcbfd48a1219dd301884198fcf' => [
                'e8ee638cef94f98e737b17b9071a45e9e7581efcbfd48a1219dd301884198fcf',
                'fishermans_landing',
                '<p>The Dolphin AM with 43 anglers returned from the morning trip with 145 rockfish 4 sheephead 5 sculpin 5 sand bass and 6 calico bass</p>',
                self::report('Dolphin', "Fisherman's Landing", '1/2 Day AM', 43, [
                    ['Rockfish', 145, 0], ['Sheephead', 4, 0], ['Sculpin', 5, 0], ['Sand Bass', 5, 0], ['Calico Bass', 6, 0],
                ]),
                ParserDiagnosticType::ProseCapturedAsEntity,
                'boat',
            ],
            'issue 33 ee518d9637d62aff30d9863c77143d3dcbdf3a3ffa8a7e5dcf1309fd160b444d' => [
                'ee518d9637d62aff30d9863c77143d3dcbdf3a3ffa8a7e5dcf1309fd160b444d',
                'fishermans_landing',
                self::dolphinPmBody(),
                self::dolphinPmReport(),
                ParserDiagnosticType::ProseCapturedAsEntity,
                'boat',
            ],
            'issue 34 6e5380ded823509816917b08aebb8da56260479a28629a9ff608412a58ade0d1' => [
                '6e5380ded823509816917b08aebb8da56260479a28629a9ff608412a58ade0d1',
                'fishermans_landing',
                self::dolphinPmBody(),
                self::dolphinPmReport(),
                ParserDiagnosticType::ProseCapturedAsEntity,
                'species',
            ],
            'issue 35 51fad4caa56d08d606b4c4dd1d15c0cbc3282e217dfa2459289d902a65e268c0' => [
                '51fad4caa56d08d606b4c4dd1d15c0cbc3282e217dfa2459289d902a65e268c0',
                'fishermans_landing',
                '<p>The Constitution returned with 43 Bluefin Tuna (5 @​100 to 225#), 7 Yellowfin, 2 Yellowtail and 1 Dorado for their 3 day trip with 21 anglers.</p>',
                self::report('Constitution', "Fisherman's Landing", '3 Day', 21, [['Bluefin Tuna', 43, 0], ['Yellowfin', 7, 0], ['Yellowtail', 2, 0], ['Dorado', 1, 0]]),
                ParserDiagnosticType::UnaccountedNumericTokens,
                'report',
            ],
            'issue 36 4dfc6c0c0dd0f62309a12c44f8fd18478a9747ccc8c8a8590fb3900d001521b0' => [
                '4dfc6c0c0dd0f62309a12c44f8fd18478a9747ccc8c8a8590fb3900d001521b0',
                'seaforth_landing',
                self::seaforthBody('The San Diego full day to the Coronado Islands, wrapped up with 30 Yellowtail for 18 anglers!'),
                self::report('San Diego', 'Seaforth Sportfishing', 'Full Day', 18, [['Yellowtail', 30, 0]]),
                ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet,
                'report',
            ],
            'issue 37 9c64d322c85d56d0c8fdc36a93f09a65af10e4005a0ec062a64e811ba4074c34' => [
                '9c64d322c85d56d0c8fdc36a93f09a65af10e4005a0ec062a64e811ba4074c34',
                'seaforth_landing',
                self::seaforthBody('The Sea Watch 3/4-day trip wrapped up another productive day with 14 Yellowtail, 17 Bonito, 12 Barracuda, 7 Calico bass ans2 Sheephead for 11 anglers. Fishing on the local grounds has been very good, with plenty of opportunities throughout the day. We recommend bringing a live bait setup with #2 to 2/0 hooks, depending on the bait size. The Sea Watch departs daily at 7:00 AM and returns at 4:00 PM. Rod rentals and fishing licenses are available for purchase at check-in. Reserve your spot today—we&#039;re a definite run all week!'),
                self::report('Sea Watch', 'Seaforth Sportfishing', '3/4 Day', 11, [
                    ['Yellowtail', 14, 0], ['Bonito', 17, 0], ['Barracuda', 12, 0], ['Calico Bass', 7, 0], ['Sheephead', 2, 0],
                ]),
                ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet,
                'report',
            ],
            'issue 38 00f4e76dedfdafd800f91dada1a11a626b7fe20cf8e3e45d0e4aa49de25a85aa' => [
                '00f4e76dedfdafd800f91dada1a11a626b7fe20cf8e3e45d0e4aa49de25a85aa',
                'fishermans_landing',
                '<p>The Dolphin P M trip had 85 Sandbass, 4 Calico Bass, 1 Yellowtail, and 4 Sculpin for 35 anglers.</p>',
                self::report('Dolphin', "Fisherman's Landing", '1/2 Day PM', 35, [['Sandbass', 85, 0], ['Calico Bass', 4, 0], ['Yellowtail', 1, 0], ['Sculpin', 4, 0]]),
                ParserDiagnosticType::ProseCapturedAsEntity,
                'boat',
            ],
            'issue 39 43e7473c671fa69b5bec1b88aad190dd73fc651ffb0c2b1e59a4bd7d86215e6e' => [
                '43e7473c671fa69b5bec1b88aad190dd73fc651ffb0c2b1e59a4bd7d86215e6e',
                'fishermans_landing',
                '<p>The AM Dolphin trip captured LIMITS (140) of Sand Bass, 1 Yellowtail (25 lbs), 1 Halibut (23 lbs), 25 Bonito for 35 anglers.</p>',
                self::report('Dolphin', "Fisherman's Landing", '1/2 Day AM', 35, [['Sand Bass', 140, 0], ['Yellowtail', 1, 0], ['Halibut', 1, 0], ['Bonito', 25, 0]]),
                ParserDiagnosticType::ProseCapturedAsEntity,
                'boat',
            ],
            'issue 40 c42ebf04dbe06e1bc7659bd0368156702ef7ccd67122c5abdfc3a255a3650853' => [
                'c42ebf04dbe06e1bc7659bd0368156702ef7ccd67122c5abdfc3a255a3650853',
                'fishermans_landing',
                '<p>The Constitution called in with 42 (limits) of Bluefin up to 120 lbs. and 4 Yellowfin for the first day of a 2.5 day with 21 anglers.</p>',
                self::report('Constitution', "Fisherman's Landing", '2.5 Day', 21, [['Bluefin', 42, 0], ['Yellowfin', 4, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
            'issue 41 0e0239616524a29bec4a827b6f05c2c47f5b13c9db6a65b28452c424777cfd0a' => [
                '0e0239616524a29bec4a827b6f05c2c47f5b13c9db6a65b28452c424777cfd0a',
                'fishermans_landing',
                '<p>The Tomahawk just called in with LIMITS (56) of Bluefin Tuna fo their 2 day trip with 14 anglers aboard.</p>',
                self::report('Tomahawk', "Fisherman's Landing", '2 Day', 14, [['Bluefin Tuna', 56, 0]]),
                ParserDiagnosticType::UnknownAlias,
                'species',
            ],
        ];
    }

    /** @param array<int, array{string, int, int}> $species */
    private static function report(string $boat, string $landing, ?string $tripType, ?int $anglers, array $species): array
    {
        return [
            'boat' => $boat,
            'landing' => $landing,
            'trip_type' => $tripType,
            'anglers' => $anglers,
            'species' => array_map(fn (array $count): array => [
                'name' => $count[0],
                'retained' => $count[1],
                'released' => $count[2],
            ], $species),
        ];
    }

    private static function seaforthBody(string $reportedItem): string
    {
        return <<<HTML
        <ul>
            <li>{$reportedItem}</li>
            <li>The New Seaforth finished their AM Half Day with 2 Yellowtail for 2 anglers.</li>
        </ul>
        HTML;
    }

    private static function dolphinFullDayBody(): string
    {
        return '<p>The Dolphin on a full day trip returned with LIMITS (130) of Whitefish, 6 Sculpin, 3 Calico Bass, 2 Sheephead, and 1 Yellowtail for 26 anglers.</p>';
    }

    private static function dolphinFullDayReport(): array
    {
        return self::report('Dolphin', "Fisherman's Landing", 'Full Day', 26, [
            ['Whitefish', 130, 0], ['Sculpin', 6, 0], ['Calico Bass', 3, 0], ['Sheephead', 2, 0], ['Yellowtail', 1, 0],
        ]);
    }

    private static function dolphinPmBody(): string
    {
        return '<p>The Dolphin PM with 43 anglers returned with 41 Bonito, 15 Calico Bass with 45 released, 13 Sheephead, and 15 Rockfish</p>';
    }

    private static function dolphinPmReport(): array
    {
        return self::report('Dolphin', "Fisherman's Landing", '1/2 Day PM', 43, [
            ['Bonito', 41, 0], ['Calico Bass', 15, 45], ['Sheephead', 13, 0], ['Rockfish', 15, 0],
        ]);
    }

    private function reportSnapshot(ParsedTripReportData $report): array
    {
        return [
            'boat' => $report->boatName,
            'landing' => $report->landingName,
            'trip_type' => $report->tripTypeName,
            'anglers' => $report->anglers,
            'species' => collect($report->speciesCounts)
                ->map(fn (ParsedSpeciesCountData $count): array => [
                    'name' => $count->speciesName,
                    'retained' => $count->count,
                    'released' => $count->releasedCount,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{
     *     boat: string,
     *     landing: string,
     *     trip_type: ?string,
     *     anglers: ?int,
     *     species: array<int, array{name: string, retained: int, released: int}>
     * }  $expectedReport
     * @return array<int, ParserDiagnosticData>
     */
    private function diagnostics(RawPayloadData $payload, ParsedFishCountCollection $parsed, array $expectedReport): array
    {
        $this->seed(DatabaseSeeder::class);

        $source = ScrapeSource::query()->where('slug', $payload->sourceKey)->firstOrFail();
        $landing = Landing::query()->where('name', $expectedReport['landing'])->firstOrFail();

        Boat::query()->firstOrCreate(
            ['slug' => Str::slug($expectedReport['boat'])],
            ['landing_id' => $landing->id, 'name' => $expectedReport['boat']],
        );

        foreach ($expectedReport['species'] as $count) {
            Species::query()->firstOrCreate(
                ['slug' => Str::slug($count['name'])],
                ['name' => $count['name'], 'is_active' => true],
            );
        }

        if ($expectedReport['trip_type'] !== null) {
            TripType::query()->firstOrCreate(
                ['slug' => Str::slug($expectedReport['trip_type'])],
                ['name' => $expectedReport['trip_type'], 'is_active' => true],
            );
        }

        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => $payload->targetDate,
        ]);
        $storedPayload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $payload->targetDate,
            'url' => $payload->url,
            'payload' => $payload->body,
            'payload_hash' => hash('sha256', $payload->body),
            'fetched_at' => now(),
        ]);

        return app(ParsedReportValidator::class)->validate($storedPayload, $payload, $parsed);
    }

    private function sourceUrl(string $sourceKey): string
    {
        return match ($sourceKey) {
            'fishermans_landing' => 'https://www.fishermanslanding.com/fishcounts.php',
            'seaforth_landing' => 'https://www.seaforthlanding.com/fishcounts.php',
        };
    }
}
