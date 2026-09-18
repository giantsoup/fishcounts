<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticType;
use App\Models\RawScrapePayload;
use App\Services\Parsing\DiagnosticContextFactory;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeptemberParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, array{string, int, int}> $counts */
    #[DataProvider('productionParagraphs')]
    public function test_production_paragraph_preserves_report_and_catches(string $source, string $paragraph, string $boat, ?string $trip, ?int $anglers, array $counts): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $body = $source === 'seaforth_landing' ? "<ul><li>{$paragraph}</li></ul>" : "<p>{$paragraph}</p>";
        $payload = new RawPayloadData($source, CarbonImmutable::parse('2026-09-17'), 'https://example.test/counts', $body);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertCount(1, $parsed->tripReports);
        $report = $parsed->tripReports->sole();
        $this->assertSame([$boat, $trip, $anglers], [$report->boatName, $report->tripTypeName, $report->anglers]);
        if ($source === 'fishermans_landing') {
            $this->assertSame($paragraph, $report->rawFishCountText);
        }
        $this->assertSame($counts, collect($report->speciesCounts)->map(fn (ParsedSpeciesCountData $count): array => [$count->speciesName, $count->count, $count->releasedCount])->all());
        $stored = new RawScrapePayload(['payload_hash' => hash('sha256', $body)]);
        $diagnostics = app(ParsedReportValidator::class)->validate($stored, $payload, $parsed);
        $this->assertSame([], collect($diagnostics)->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)->map(fn (ParserDiagnosticData $diagnostic): array => [$diagnostic->type->value, $diagnostic->rawValue])->all());
    }

    #[DataProvider('tableSources')]
    public function test_ai_table_evidence_does_not_duplicate_angler_counts(string $source): void
    {
        $body = "<table><tr><td class='HMFishCountBreak'>Thursday September 17th, 2026</td></tr>\n<tr>\n<td>Sea Adventure 80</td>\n<td>3.5 Day</td>\n<td>20</td>\n<td>300 Yellowfin Tuna, 1 Striped Marlin</td>\n</tr>\n</table>";
        $payload = new RawPayloadData($source, CarbonImmutable::parse('2026-09-17'), 'https://example.test/counts', $body);
        $report = new ParsedTripReportData(
            sourceKey: $source, tripDate: $payload->targetDate, regionName: 'San Diego', landingName: null, boatName: 'Sea Adventure 80', tripTypeName: '3.5 Day', anglers: 20,
            rawFishCountText: 'Sea Adventure 80 3.5 Day 20 300 Yellowfin Tuna, 1 Striped Marlin',
            speciesCounts: [new ParsedSpeciesCountData('Yellowfin Tuna', 300), new ParsedSpeciesCountData('Striped Marlin', 1)],
            metadata: ['format' => 'ai-structured-output'],
        );
        $paragraph = app(DiagnosticContextFactory::class)->paragraphForReport($payload, $report);
        $this->assertSame('Sea Adventure 80 | 3.5 Day | 20 | 300 Yellowfin Tuna, 1 Striped Marlin |', $paragraph);
        $this->assertSame([], $this->structuralDiagnostics($payload, $report));
    }

    /** @return array<string, array{string}> */
    public static function tableSources(): array
    {
        return ['H&M' => ['hm_landing'], 'Point Loma' => ['point_loma_sportfishing']];
    }

    public function test_ai_date_prefixed_report_is_present_but_other_boat_is_still_missing(): void
    {
        $paragraph = 'The Pacific Queen called in with 144 Dorado, 31 Yellowfin and 6 Wahoo for their 3 day trip with 25 anglers.';
        $body = "<p>9/15/2026<br />\n{$paragraph}<br />&nbsp;\nThe Poseidon returned with 5 Dorado for 10 anglers.</p>";
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-09-15'), 'https://example.test/counts', $body);
        $report = new ParsedTripReportData(
            sourceKey: 'fishermans_landing', tripDate: $payload->targetDate, regionName: 'San Diego', landingName: null, boatName: 'Pacific Queen', tripTypeName: '3 Day', anglers: 25,
            rawFishCountText: '9/15/2026 '.$paragraph,
            speciesCounts: [new ParsedSpeciesCountData('Dorado', 144), new ParsedSpeciesCountData('Yellowfin', 31), new ParsedSpeciesCountData('Wahoo', 6)],
            metadata: ['format' => 'ai-structured-output'],
        );
        $diagnostics = $this->structuralDiagnostics($payload, $report);
        $this->assertCount(1, $diagnostics);
        $this->assertSame(ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet, $diagnostics[0]->type);
        $this->assertSame('Poseidon', $diagnostics[0]->rawValue);
    }

    #[DataProvider('evidencePrefixes')]
    public function test_ai_evidence_covering_two_trips_does_not_hide_a_missing_trip(string $prefix): void
    {
        $morningReport = 'The Dolphin AM trip caught 10 Rockfish for 20 anglers.';
        $afternoonReport = 'The Dolphin PM trip caught 8 Calico Bass for 12 anglers.';
        $payload = new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-09-17'),
            url: 'https://example.test/counts',
            body: "<p>{$morningReport}</p><p>{$afternoonReport}</p>",
        );
        $report = new ParsedTripReportData(
            sourceKey: $payload->sourceKey,
            tripDate: $payload->targetDate,
            regionName: 'San Diego',
            landingName: null,
            boatName: 'Dolphin',
            tripTypeName: '1/2 Day AM',
            anglers: 20,
            rawFishCountText: "{$prefix}{$morningReport} {$afternoonReport}",
            speciesCounts: [new ParsedSpeciesCountData('Rockfish', 10)],
            metadata: ['format' => 'ai-structured-output'],
        );

        $diagnostics = $this->structuralDiagnostics($payload, $report);

        $this->assertTrue(collect($diagnostics)->contains(
            fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet
                && $diagnostic->context['sanitized_paragraph'] === $afternoonReport,
        ));
    }

    /** @return array<string, array{string}> */
    public static function evidencePrefixes(): array
    {
        return ['no date' => [''], 'date prefix' => ['9/17/2026 ']];
    }

    /** @return array<int, ParserDiagnosticData> */
    private function structuralDiagnostics(RawPayloadData $payload, ParsedTripReportData $report): array
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $parsed = new ParsedFishCountCollection(collect([$report]), 'ai-primary-v5', 'ai-structured-output');
        $stored = new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]);

        return collect(app(ParsedReportValidator::class)->validate($stored, $payload, $parsed))
            ->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)->values()->all();
    }

    /** @return array<string, array{string, string, string, ?string, ?int, array<int, array{string, int, int}>}> */
    public static function productionParagraphs(): array
    {
        return [
            'poseidon' => [
                'fishermans_landing',
                'The Poseidon just called with 14 Wahoo, 92 Dorado, 43 Yellowfin Tuna, and 1 Striped Marlin released for their 2.5 day trip with 18 anglers.',
                'Poseidon',
                '2.5 Day',
                18,
                [
                    ['Wahoo', 14, 0],
                    ['Dorado', 92, 0],
                    ['Yellowfin Tuna', 43, 0],
                    ['Striped Marlin', 0, 1],
                ],
            ],
            'dolphin three quarter' => [
                'fishermans_landing',
                'The Dolphin 3/4 trip caught 123 Bonito, 39 Calico Bass, 17 Sand Bass, 6 Yellowtail, 49 Barracuda, 4 Sheephead, 15 Sculpin, and 11 Rockfish for 43 anglers.',
                'Dolphin',
                '3/4 Day',
                43,
                [
                    ['Bonito', 123, 0],
                    ['Calico Bass', 39, 0],
                    ['Sand Bass', 17, 0],
                    ['Yellowtail', 6, 0],
                    ['Barracuda', 49, 0],
                    ['Sheephead', 4, 0],
                    ['Sculpin', 15, 0],
                    ['Rockfish', 11, 0],
                ],
            ],
            'dolphin pm missing verb' => [
                'fishermans_landing',
                'The Dolphin PM trip 41 Sandbass, 30 Calico Bass, 45 Bonito, 2 yellowtail, 16 Sculpin, and 10 Sheephead for 58 anglers.',
                'Dolphin',
                '1/2 Day PM',
                58,
                [
                    ['Sandbass', 41, 0],
                    ['Calico Bass', 30, 0],
                    ['Bonito', 45, 0],
                    ['Yellowtail', 2, 0],
                    ['Sculpin', 16, 0],
                    ['Sheephead', 10, 0],
                ],
            ],
            'lucky return' => [
                'fishermans_landing',
                'The Lucky B return with 12 Yellowfin, 1 Skipjack and 2 Dorado for their Fullday trip with 4 anglers.',
                'Lucky B',
                'Full Day',
                4,
                [
                    ['Yellowfin', 12, 0],
                    ['Skipjack', 1, 0],
                    ['Dorado', 2, 0],
                ],
            ],
            'islander spacing' => [
                'fishermans_landing',
                'The Islander returned this AM with 150 Dorado, 1 Wahoo, and 1 Yellowfin for 26 anglers on a3 day trip.',
                'Islander',
                '3 Day',
                26,
                [
                    ['Dorado', 150, 0],
                    ['Wahoo', 1, 0],
                    ['Yellowfin', 1, 0],
                ],
            ],
            'liberty private charter' => [
                'fishermans_landing',
                'The Liberty called in with 4 Wahoo, 96 Dorado and 1 Yellowfin tuna on a 2.5 day private charter with 24 anglers.',
                'Liberty',
                '2.5 Day',
                24,
                [
                    ['Wahoo', 4, 0],
                    ['Dorado', 96, 0],
                    ['Yellowfin Tuna', 1, 0],
                ],
            ],
            'constitution spacing' => [
                'fishermans_landing',
                'The Constitution called in with LIMITS (108) of Dorado, 1 Yellow Tail, and 14 Yellowfin Tuna on their 3.5 day trip for18 anglers.',
                'Constitution',
                '3.5 Day',
                18,
                [
                    ['Dorado', 108, 0],
                    ['Yellow Tail', 1, 0],
                    ['Yellowfin Tuna', 14, 0],
                ],
            ],
            'dolphin angler first suffix' => [
                'fishermans_landing',
                'The Dolphin Called in with 160 Bonito, 76 Sand Bass, 29 Calico with 100 released, 24 Sheephead, 35 Rcokfish, 1 Halibut and 2 Black Sea Bass released for their 32 angler 3/4 trip',
                'Dolphin',
                '3/4 Day',
                32,
                [
                    ['Bonito', 160, 0],
                    ['Sand Bass', 76, 0],
                    ['Calico', 29, 100],
                    ['Sheephead', 24, 0],
                    ['Rcokfish', 35, 0],
                    ['Halibut', 1, 0],
                    ['Black Sea Bass', 0, 2],
                ],
            ],
            'pegasus both released' => [
                'fishermans_landing',
                'The Pegasus returned this AM with 36 Dorado, 1 Yellowfin, and 1 Blue Marlin and 1 Stripped Marlin both released for 18 anglers ona 1.5 day trip.',
                'Pegasus',
                '1.5 Day',
                18,
                [
                    ['Dorado', 36, 0],
                    ['Yellowfin', 1, 0],
                    ['Blue Marlin', 0, 1],
                    ['Striped Marlin', 0, 1],
                ],
            ],
            'islander approximate releases' => [
                'fishermans_landing',
                'The Islander returned this morning with 56 Dorado, 23 Yellowfin Tuna (100+ released), 2 Wahoo, and 1 Striped Marlin for 25 anglers on their 3 day charter.',
                'Islander',
                '3 Day',
                25,
                [
                    ['Dorado', 56, 0],
                    ['Yellowfin Tuna', 23, 100],
                    ['Wahoo', 2, 0],
                    ['Striped Marlin', 1, 0],
                ],
            ],
            'dolphin fpr' => [
                'fishermans_landing',
                'The Dolphin AM trip had 112 Bonito, 3 Yellowtail, 40 Sandbass, 45 Calico Bass, 12 Rockfish, and 2 Black Seabass released fpr 58 anglers.',
                'Dolphin',
                '1/2 Day AM',
                58,
                [
                    ['Bonito', 112, 0],
                    ['Yellowtail', 3, 0],
                    ['Sandbass', 40, 0],
                    ['Calico Bass', 45, 0],
                    ['Rockfish', 12, 0],
                    ['Black Seabass', 0, 2],
                ],
            ],
            'tribute trailing prose' => [
                'seaforth_landing',
                'The Tribute checked in from their 1.5 day trip with limits of Dorado, 100 Yellowfin, 9 Yellowtail and 1 Marlin to start their trip.',
                'Tribute',
                '1.5 Day',
                null,
                [
                    ['Yellowfin', 100, 0],
                    ['Yellowtail', 9, 0],
                    ['Marlin', 1, 0],
                ],
            ],
            'tribute angler prose' => [
                'seaforth_landing',
                'The Tribute overnight charter with 25 anglers finished up their trip with 82 Yellowfin Tuna, 7 Dorado, and 6 Skipjack Tuna.',
                'Tribute',
                'Overnight',
                25,
                [
                    ['Yellowfin Tuna', 82, 0],
                    ['Dorado', 7, 0],
                    ['Skipjack Tuna', 6, 0],
                ],
            ],
            'pacific queen' => [
                'fishermans_landing',
                'The Pacific Queen called in with 144 Dorado, 31 Yellowfin and 6 Wahoo for their 3 day trip with 25 anglers.',
                'Pacific Queen',
                '3 Day',
                25,
                [
                    ['Dorado', 144, 0],
                    ['Yellowfin', 31, 0],
                    ['Wahoo', 6, 0],
                ],
            ],
            'san diego hooks' => [
                'seaforth_landing',
                'The San Diego on their full day trip finished up with 131 Yellowfin, 1 Dorado and 22 Skipjack. Flyline live bait on 15lb-30lb has been getting it done along with sniper style jigs in those smaller sizes. Bring a multiple sizes for hooks between #2-2/0. Passports are Not required.',
                'San Diego',
                'Full Day',
                null,
                [
                    ['Yellowfin', 131, 0],
                    ['Dorado', 1, 0],
                    ['Skipjack', 22, 0],
                ],
            ],
            'san diego adjacent catches' => [
                'seaforth_landing',
                'The San Diego on their full day trip finished up with 132 Yellowfin, 3 Dorado 3 Yellowtail. Flyline live bait on 15lb-30lb has been getting it done along with sniper style jigs in those smaller sizes. Bring a multiple sizes for hooks between #2-2/0.',
                'San Diego',
                'Full Day',
                null,
                [
                    ['Yellowfin', 132, 0],
                    ['Dorado', 3, 0],
                    ['Yellowtail', 3, 0],
                ],
            ],
            'sea watch departure' => [
                'seaforth_landing',
                'The Sea Watch is a Definite Run on their Full Day trip tomorrow Wednesday 9/16/26 departing at 5:30AM. The Sea Watch on their full day trip today finished up with 31 Yellowfin, 2 Dorado and 1 Striped Marlin. Current sniper style jigs have been getting bit well but also good to have a 20-30lb live bait setup.',
                'Sea Watch',
                'Full Day',
                null,
                [
                    ['Yellowfin', 31, 0],
                    ['Dorado', 2, 0],
                    ['Striped Marlin', 1, 0],
                ],
            ],
            'sea watch tackle range' => [
                'seaforth_landing',
                'The Sea Watch on their full day trip finished up with 16 Yellowfin, 1 Skipjack and 2 Dorado. Current sniper style jigs have been getting bit well but also good to have a 25-30lb live bait setup or surface iron rod. Don\'t forget those passports.',
                'Sea Watch',
                'Full Day',
                null,
                [
                    ['Yellowfin', 16, 0],
                    ['Skipjack', 1, 0],
                    ['Dorado', 2, 0],
                ],
            ],
            'san diego tackle range' => [
                'seaforth_landing',
                'The San Diego on their full day trip finished up with 15 Yellowtail and 80 Bonito. Current sniper style jigs have been getting bit well but also good to have a 25-30lb live bait setup or surface iron rod. Don\'t forget those passports.',
                'San Diego',
                'Full Day',
                null,
                [
                    ['Yellowtail', 15, 0],
                    ['Bonito', 80, 0],
                ],
            ],
        ];
    }
}
