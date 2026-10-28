<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticType;
use App\Models\RawScrapePayload;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OctoberParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, array{int, int}> $counts */
    #[DataProvider('narrativeReports')]
    public function test_saved_october_narratives_preserve_identity_and_catches(string $source, string $html, string $boat, ?string $trip, ?int $anglers, array $counts): void
    {
        $payload = new RawPayloadData($source, CarbonImmutable::parse('2026-10-24'), 'https://example.test/counts', $html);
        $report = app(SourceSpecificFishCountParser::class)->parse($payload)->tripReports->sole();

        $this->assertSame([$boat, $trip, $anglers], [$report->boatName, $report->tripTypeName, $report->anglers]);
        $this->assertSame($counts, collect($report->speciesCounts)->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => [$count->count, $count->releasedCount]])->all());
        $this->assertStringContainsString($boat, $report->rawFishCountText);
    }

    public function test_complete_date_section_keeps_neighboring_trips_and_missing_trip_diagnostics(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-10-24'), 'https://example.test/counts', <<<'HTML'
            <div><p>10/24/2026<br>
            The <strong>Pacific Queen</strong> called in with 251&nbsp;Yellowfin Tuna averaging 18 to 35 lbs with a few up to 90 pounds and 8 Yellowtail on their 2nd day of a 3 day charter for 22 anglers.<br>
            The <strong>Liberty</strong> call in with 25 Yellowfin Tuna (20-60 lbs), 2 Wahoo, 10 Yellowtail (15-30 lbs) for 14 anglers on their 2 day trip.</p>
            <p>The <strong>Tomahawk</strong> on a 2 day trip has 39 Yellowfin Tuna, 35 Skipjack Tuna, 7 Yellowtail, 1 Wahoo for 19 anglers.<br>
            The <strong>Dolphin</strong> AM charter caught 22 Calico Bass, 47 Sand Bass, 2 Yellowtail, 2 Halibut, and 103 White Fish for 45 anglers.</p>
            <p>10/23/2026<br>The Pacific Queen caught 12 Yellowtail for 10 anglers.</p></div>
            HTML);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);

        $this->assertSame(['Pacific Queen', 'Liberty', 'Tomahawk', 'Dolphin'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertStringContainsString('averaging 18 to 35 lbs with a few up to 90 pounds', $parsed->tripReports[0]->rawFishCountText);
        $this->assertSame([], $this->structuralDiagnostics($payload, $parsed));

        $missing = $this->structuralDiagnostics($payload, new ParsedFishCountCollection($parsed->tripReports->slice(1)->values(), $parsed->parserVersion, $parsed->format));
        $this->assertCount(1, $missing);
        $this->assertSame(ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet, $missing[0]->type);
        $this->assertStringContainsString('Pacific Queen', $missing[0]->context['sanitized_paragraph']);
    }

    public function test_approximate_counts_preserve_source_evidence_and_detect_a_missing_catch(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-10-24'), 'https://example.test/counts', self::narrativeReports()['approximate bass'][1]);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertStringContainsString('110+ Bass', $parsed->tripReports->sole()->rawFishCountText);
        $this->assertSame([], $this->structuralDiagnostics($payload, $parsed));

        $report = $parsed->tripReports->sole();
        $reportWithoutBass = new ParsedTripReportData(
            sourceKey: $report->sourceKey, tripDate: $report->tripDate, regionName: $report->regionName,
            landingName: $report->landingName, boatName: $report->boatName, tripTypeName: $report->tripTypeName,
            anglers: $report->anglers, rawFishCountText: $report->rawFishCountText,
            speciesCounts: array_values(array_filter($report->speciesCounts, fn (ParsedSpeciesCountData $count): bool => $count->speciesName !== 'Bass')),
            metadata: $report->metadata,
        );
        $missing = $this->structuralDiagnostics($payload, new ParsedFishCountCollection(collect([$reportWithoutBass]), $parsed->parserVersion, $parsed->format));
        $this->assertTrue(collect($missing)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnaccountedNumericTokens && in_array('110', $diagnostic->context['evidence']['unaccounted_tokens'], true)));
    }

    public function test_trip_category_does_not_erase_species_names_from_numeric_evidence(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = new RawPayloadData('hm_landing', CarbonImmutable::parse('2026-10-24'), 'https://example.test/counts', '<table><tr><td class="HMFishCountBreak" colspan="4">Saturday October 24th, 2026</td></tr><tr><th>Boat</th><th>Trip</th><th>Anglers</th><th>Fish Counts</th></tr><tr><td>Tradition</td><td>Lobster</td><td>7</td><td>49 Spiny Lobster, 53 Spiny Lobster Released</td></tr></table>');
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame([[49, 53]], collect($parsed->tripReports->sole()->speciesCounts)->map(fn (ParsedSpeciesCountData $count): array => [$count->count, $count->releasedCount])->all());
        $this->assertSame([], $this->structuralDiagnostics($payload, $parsed));

        $report = $parsed->tripReports->sole();
        $missingCatches = new ParsedTripReportData(
            sourceKey: $report->sourceKey, tripDate: $report->tripDate, regionName: $report->regionName,
            landingName: $report->landingName, boatName: $report->boatName, tripTypeName: $report->tripTypeName,
            anglers: $report->anglers, rawFishCountText: $report->rawFishCountText, speciesCounts: [], metadata: $report->metadata,
        );
        $missing = $this->structuralDiagnostics($payload, new ParsedFishCountCollection(collect([$missingCatches]), $parsed->parserVersion, $parsed->format));
        $this->assertTrue(collect($missing)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnaccountedNumericTokens && $diagnostic->rawValue === '49, 53'));
    }

    public function test_unnumbered_limits_keep_explicit_catches_and_a_qualitative_claim(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = new RawPayloadData('seaforth_landing', CarbonImmutable::parse('2026-10-24'), 'https://example.test/counts', '<ul><li>The Highliner 1.5 Day trip finished with 16 bluefin tuna, 17 yellowfin tuna, 5 skipjack, 19 yellowtail and limits of reds.</li></ul>');
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(['Bluefin Tuna', 'Yellowfin Tuna', 'Skipjack', 'Yellowtail', 'Red Rock Fish'], collect($parsed->tripReports->sole()->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame([16, 17, 5, 19, null], collect($parsed->tripReports->sole()->speciesCounts)->pluck('count')->all());
        $diagnostics = $this->structuralDiagnostics($payload, $parsed);
        $this->assertSame([], $diagnostics);
    }

    public function test_multiple_unnumbered_limits_do_not_hide_explicit_catches(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-10-24'), 'https://example.test/counts', '<p>The Dolphin finished with limits of Calico Bass, Limits Bonito and 17 Yellowtail, 26 Whitefish, and 21 Sheephead on their 3/4 day trip for 25 anglers.</p>');
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(['Yellowtail', 'Whitefish', 'Sheephead', 'Calico Bass', 'Bonito'], collect($parsed->tripReports->sole()->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame([17, 26, 21, null, null], collect($parsed->tripReports->sole()->speciesCounts)->pluck('count')->all());
        $diagnostics = $this->structuralDiagnostics($payload, $parsed);
        $this->assertSame([], $diagnostics);
    }

    public function test_unquantified_limits_and_incorrect_counts_share_one_complete_diagnostic(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-10-24'), 'https://example.test/counts', '<p>The Dolphin caught limits of Calico Bass, 17 Yellowtail for 25 anglers.</p>');
        $report = new ParsedTripReportData(
            sourceKey: $payload->sourceKey, tripDate: $payload->targetDate, regionName: 'San Diego',
            landingName: "Fisherman's Landing", boatName: 'Dolphin', tripTypeName: null, anglers: 25,
            rawFishCountText: strip_tags($payload->body), speciesCounts: [new ParsedSpeciesCountData('Yellowtail', 16)],
        );
        $diagnostics = $this->structuralDiagnostics($payload, new ParsedFishCountCollection(collect([$report]), 'test', 'narrative'));
        $this->assertCount(1, $diagnostics);
        $this->assertSame(['Calico Bass'], $diagnostics[0]->context['evidence']['unquantified_species']);
        $this->assertSame([['species' => 'Yellowtail', 'extracted_retained' => 16, 'extracted_released' => 0, 'source_retained' => 17, 'source_released' => 0]], $diagnostics[0]->context['evidence']['mismatches']);
    }

    /** @return array<int, ParserDiagnosticData> */
    private function structuralDiagnostics(RawPayloadData $payload, ParsedFishCountCollection $parsed): array
    {
        return collect(app(ParsedReportValidator::class)->validate(new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]), $payload, $parsed))
            ->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)->values()->all();
    }

    /** @return array<string, array{string, string, string, ?string, ?int, array<string, array{int, int}>}> */
    public static function narrativeReports(): array
    {
        return [
            'plus pounds' => ['fishermans_landing', '<p>The Dolphin finished their 3/4 day trip with 40 Calico Bass, 28 Sandbass, 11 Sheephead, 38 Bonito, 6 Sculpin, 5 Yellowtail, and 1 White Seabass 50 plus pounds for 24 anglers.</p>', 'Dolphin', '3/4 Day', 24, ['Calico Bass' => [40, 0], 'Sandbass' => [28, 0], 'Sheephead' => [11, 0], 'Bonito' => [38, 0], 'Sculpin' => [6, 0], 'Yellowtail' => [5, 0], 'White Seabass' => [1, 0]]],
            'hash weight' => ['fishermans_landing', '<p>The Dolphin caught 17 Yellowtail up to 25#, 66 Calico Bass, 11 Sculpin, 5 Sheephead, 3 Whitefish, 22 Bonito and 1 Skipjack for their 3/4 day trip with 45 anglers.</p>', 'Dolphin', '3/4 Day', 45, ['Yellowtail' => [17, 0], 'Calico Bass' => [66, 0], 'Sculpin' => [11, 0], 'Sheephead' => [5, 0], 'Whitefish' => [3, 0], 'Bonito' => [22, 0], 'Skipjack' => [1, 0]]],
            'majority weight' => ['seaforth_landing', '<ul><li>The Apollo 2 Day trip finished with 12 bluefin tuna with the majority of fish around 150 lb, 12 yellowfin tuna, 9 yellowtail, 40 vermilion rock fish, and 80 rock fish.</li></ul>', 'Apollo', '2 Day', null, ['Bluefin Tuna' => [12, 0], 'Yellowfin Tuna' => [12, 0], 'Yellowtail' => [9, 0], 'Vermilion Rock Fish' => [40, 0], 'Rock Fish' => [80, 0]]],
            'returning from' => ['fishermans_landing', '<p>The Pegasus returning from a 2 day trip with 35 Yellowtail, 18 Yellowfin, 25 Skip Jack, and 1 Dorado for 17 anglers.</p>', 'Pegasus', '2 Day', 17, ['Yellowtail' => [35, 0], 'Yellowfin' => [18, 0], 'Skip Jack' => [25, 0], 'Dorado' => [1, 0]]],
            'call in' => ['fishermans_landing', '<p>The Liberty call in with 25 Yellowfin Tuna (20-60 lbs), 2 Wahoo, 10 Yellowtail (15-30 lbs) for 14 anglers on their 2 day trip.</p>', 'Liberty', '2 Day', 14, ['Yellowfin Tuna' => [25, 0], 'Wahoo' => [2, 0], 'Yellowtail' => [10, 0]]],
            'trip before has' => ['fishermans_landing', '<p>The Tomahawk on a 2 day trip has 39 Yellowfin Tuna, 35 Skipjack Tuna, 7 Yellowtail, 1 Wahoo for 19 anglers.</p>', 'Tomahawk', '2 Day', 19, ['Yellowfin Tuna' => [39, 0], 'Skipjack Tuna' => [35, 0], 'Yellowtail' => [7, 0], 'Wahoo' => [1, 0]]],
            'AM charter' => ['fishermans_landing', '<p>The <strong>Dolphin</strong> AM charter caught 22 Calico Bass, 47 Sand Bass, 2 Yellowtail, 2 Halibut, and 103 White Fish for 45 anglers.</p>', 'Dolphin', '1/2 Day AM', 45, ['Calico Bass' => [22, 0], 'Sand Bass' => [47, 0], 'Yellowtail' => [2, 0], 'Halibut' => [2, 0], 'White Fish' => [103, 0]]],
            'averaging weights and ordinal day' => ['fishermans_landing', '<p>The Pacific Queen called in with 251&nbsp;Yellowfin Tuna averaging 18 to 35 lbs with a few up to 90 pounds and 8 Yellowtail on their 2nd day of a 3 day charter for 22 anglers.</p>', 'Pacific Queen', '3 Day', 22, ['Yellowfin Tuna' => [251, 0], 'Yellowtail' => [8, 0]]],
            'weight only catch' => ['fishermans_landing', '<p>The Pacific Queen called in with 95 Yellowfin tuna averaging 18 to 35 pounds with a few up to 90 pounds on their 1st day of a 3 day charter with 22 anglers.</p>', 'Pacific Queen', '3 Day', 22, ['Yellowfin Tuna' => [95, 0]]],
            'limited load wording' => ['fishermans_landing', '<p>The Pacific Dawen Called in with 4 YellowFin Tuna 40 to 60 pounds for their 1.5 day LL of 7 anglers.</p>', 'Pacific Dawen', '1.5 Day', 7, ['Yellowfin Tuna' => [4, 0]]],
            'first day suffix' => ['fishermans_landing', '<p>The Libetty has 25 Yellowfin Tuna&nbsp;on their first day of a 2 day trip for 22 anglers.</p>', 'Libetty', '2 Day', 22, ['Yellowfin Tuna' => [25, 0]]],
            'plural tuna' => ['fishermans_landing', '<p>The <strong>Pacific Queen</strong> just called in with 2 Bluefin Tuna (both over 200lbs) and 18 Yellowfin tunas (40-60 lbs.) for their 3 day trip with 17 anglers.</p>', 'Pacific Queen', '3 Day', 17, ['Bluefin Tuna' => [2, 0], 'Yellowfin Tunas' => [18, 0]]],
            'check in to start morning' => ['seaforth_landing', '<ul><li>The <em>Cortez</em> 1.5 day trip checked in to start their morning with 150 Yellowfin(limits) from 12-30lbs and 24 Skipjack for 15 anglers!</li></ul>', 'Cortez', '1.5 Day', 15, ['Yellowfin' => [150, 0], 'Skipjack' => [24, 0]]],
            'finished up a' => ['seaforth_landing', '<ul><li>The <em>Pacifica</em> finished up a 1 day with 2 Wahoo and 5 Dorado.</li></ul>', 'Pacifica', '1 Day', null, ['Wahoo' => [2, 0], 'Dorado' => [5, 0]]],
            'approximate bass' => ['fishermans_landing', '<p>The Dolphin just called in with 10&nbsp;Yellowtail, 5&nbsp;White Sea Bass and 110+ Bass, 40 Barracuda and 100 Barracuda released&nbsp;for their 3/4 day trip with 29 anglers. Also reporting great weather.</p>', 'Dolphin', '3/4 Day', 29, ['Yellowtail' => [10, 0], 'White Sea Bass' => [5, 0], 'Bass' => [110, 0], 'Barracuda' => [40, 100]]],
        ];
    }
}
