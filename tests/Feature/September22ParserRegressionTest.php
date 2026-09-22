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

class September22ParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, array{string, ?int, array<string, int>}> $expected */
    #[DataProvider('seaforthSections')]
    public function test_seaforth_preserves_every_trip_in_the_source_section(string $body, array $expected): void
    {
        $this->seed(DatabaseSeeder::class);
        $payload = new RawPayloadData('seaforth_landing', CarbonImmutable::parse('2026-09-20'), 'https://example.test/counts', $body);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(array_keys($expected), $parsed->tripReports->pluck('boatName')->all());
        foreach ($parsed->tripReports as $report) {
            [$trip, $anglers, $counts] = $expected[$report->boatName];
            $this->assertSame([$trip, $anglers], [$report->tripTypeName, $report->anglers]);
            $this->assertSame($counts, collect($report->speciesCounts)->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => $count->count])->all());
            $this->assertSame(0, collect($report->speciesCounts)->sum('releasedCount'));
        }
        $this->assertSame([], $this->diagnostics($payload, $parsed));
        $missing = new ParsedFishCountCollection($parsed->tripReports->reject(fn (ParsedTripReportData $report): bool => $report->boatName === 'San Diego')->values(), $parsed->parserVersion, $parsed->format);
        $this->assertTrue(collect($this->diagnostics($payload, $missing))->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet && str_contains($diagnostic->context['sanitized_paragraph'], 'San Diego')));
    }

    /** @return array<string, array{string, array<string, array{string, ?int, array<string, int>}>}> */
    public static function seaforthSections(): array
    {
        return [
            'September 19' => [<<<'HTML'
                <h4>Saturday Returning Trips</h4><ul>
                <li>The <em>Polaris Supreme</em> checked in from their 3 Day with 141 Dorado. </li>
                <li>The <em>Tribute</em> checked in from their 2.5 day trip with limits of Dorado and 21 Yellowfin to start their trip. </li>
                </ul>
                <h4>Saturday Sea Watch Full Day</h4><ul>
                <li>The <em>Sea Watch</em> wrapped up their full-day trip with an impressive count of 104 Yellowfin Tuna, 6 Dorado, and 6 Skipjack. Passports are <b>not</b> currently required for this trip. Current sniper-style jigs have been getting bit well, but it’s also a good idea to bring a 20–30 lb live-bait setup.</li>
                </ul><h4>Saturday San Diego Full Day</h4><ul>
                <li>The <em>San Diego</em> wrapped up their full-day trip with 199 Yellowfin, 2 Dorado, and 16 Skipjack for 25 anglers. Fly-lined live bait on 15–30 lb tackle has been getting it done, along with smaller-sized sniper-style jigs. Be sure to bring multiple hook sizes, ranging from #2 to 2/0. Passports are <b>not</b> required.</li>
                </ul><h4>Saturday New Seaforth Full Day</h4><ul>
                <li>The <em>New Seaforth</em> wrapped up their full-day charter with 91 Yellowfin, 4 Dorado, 1 Striped marlin, 22 Yellowtail and 12 Skipjack for 23 anglers. </li>
                </ul>
                HTML, [
                'Polaris Supreme' => ['3 Day', null, ['Dorado' => 141]],
                'Tribute' => ['2.5 Day', null, ['Yellowfin' => 21]],
                'Sea Watch' => ['Full Day', null, ['Yellowfin Tuna' => 104, 'Dorado' => 6, 'Skipjack' => 6]],
                'San Diego' => ['Full Day', 25, ['Yellowfin' => 199, 'Dorado' => 2, 'Skipjack' => 16]],
                'New Seaforth' => ['Full Day', 23, ['Yellowfin' => 91, 'Dorado' => 4, 'Striped Marlin' => 1, 'Yellowtail' => 22, 'Skipjack' => 12]],
            ]],
            'September 20' => [<<<'HTML'
                <h4>Tribute 2.5 Day</h4><ul>
                <li>The <em>Tribute</em> checked in this evening from their 2.5 day charter with limits of dorado, 103 Yellowfin Tuna, 2 Yellowtail, and 1 Wahoo.</li>
                </ul><h4>Sunday Returning Trips</h4><ul>
                <li>The <em>Apollo</em> returned from a 2.5 day trip with 201 Yellowfin Tuna, 81 Skipjack Tuna, 15 Yellowtail, and 2 Striped Marlin.</li>
                <li>The <em>Highliner</em> on their 2 day returned with 151 Yellowfin Tuna, 76 Skipjack Tuna, 5 Dorado, and 1 Wahoo.</li>
                </ul><h4>Sea Watch Full Day</h4><ul>
                <li>The <em>Sea Watch</em> on their full-day trip returned with 45 Yellowfin Tuna, 5 Skipjack Tuna, 5 Dorado, and 3 Yellowtail. Passports are <b>not</b> currently required for this trip. Current sniper-style jigs have been getting bit well, but it’s also a good idea to bring a 20–30 lb live-bait setup.</li>
                </ul><h4>San Diego Full Day</h4><ul>
                <li>The <em>San Diego</em> wrapped up their full-day trip with 211 Yellowfin, 8 Dorado, and 15 Skipjack for 36 anglers. Fly-lined live bait on 15–30 lb tackle has been getting it done, along with smaller-sized sniper-style jigs. Be sure to bring multiple hook sizes, ranging from #2 to 2/0. Passports are <b>not</b> required.
                </li></ul><h4>New Seaforth Half Day</h4><ul>
                <li>The <em>New Seaforth</em> has been seeing some excellent local action on its half-day trips, with anglers getting into Bass, Barracuda, and Bonito, along with opportunities at quality Yellowtail. A 20–30 lb live-bait setup with #4 through 2/0 hooks is recommended.</li>
                </ul>
                HTML, [
                'Tribute' => ['2.5 Day', null, ['Yellowfin Tuna' => 103, 'Yellowtail' => 2, 'Wahoo' => 1]],
                'Apollo' => ['2.5 Day', null, ['Yellowfin Tuna' => 201, 'Skipjack Tuna' => 81, 'Yellowtail' => 15, 'Striped Marlin' => 2]],
                'Highliner' => ['2 Day', null, ['Yellowfin Tuna' => 151, 'Skipjack Tuna' => 76, 'Dorado' => 5, 'Wahoo' => 1]],
                'Sea Watch' => ['Full Day', null, ['Yellowfin Tuna' => 45, 'Skipjack Tuna' => 5, 'Dorado' => 5, 'Yellowtail' => 3]],
                'San Diego' => ['Full Day', 36, ['Yellowfin' => 211, 'Dorado' => 8, 'Skipjack' => 15]],
            ]],
        ];
    }

    public function test_white_seabass_survive_a_following_weight_sentence(): void
    {
        $this->seed(DatabaseSeeder::class);
        $paragraph = 'The <strong>Dolphin</strong> Called in with 36 Bonito,30 Rock Fish, 22 Calico Bass, 18 Sand Bass, 2 Sculpin, 5 Sheephead, 6 Barracuda, 1 Yellowtail and 2 White Seabass they were 20 and 30 pounds.&nbsp;for their 3/4 day trip with 24 anglers.';
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-09-21'), 'https://example.test/counts', "<p>9/21/2026<br />\n{$paragraph}<br />\n9/20/2026<br />\nThe Dolphin caught 10 Rockfish for 20 anglers.</p>");
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $report = $parsed->tripReports->sole();
        $this->assertSame(['Dolphin', '3/4 Day', 24], [$report->boatName, $report->tripTypeName, $report->anglers]);
        $this->assertStringContainsString('2 White Seabass they were 20 and 30 pounds.', $report->rawFishCountText);
        $this->assertSame(['Bonito' => 36, 'Rock Fish' => 30, 'Calico Bass' => 22, 'Sand Bass' => 18, 'Sculpin' => 2, 'Sheephead' => 5, 'Barracuda' => 6, 'Yellowtail' => 1, 'White Seabass' => 2], collect($report->speciesCounts)->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => $count->count])->all());
        $this->assertSame([], $this->diagnostics($payload, $parsed));
    }

    public function test_weight_numbers_do_not_hide_additional_or_omitted_catches(): void
    {
        $paragraph = 'The Dolphin caught 20 Bonito, 2 White Seabass they were 20 and 30 pounds and 30 Yellowtail for 24 anglers.';
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-09-21'), 'https://example.test/counts', "<p>{$paragraph}</p>");
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $report = $parsed->tripReports->sole();
        $this->assertSame([['Bonito', 20], ['White Seabass', 2], ['Yellowtail', 30]], collect($report->speciesCounts)->map(fn (ParsedSpeciesCountData $count): array => [$count->speciesName, $count->count])->all());
        $incomplete = new ParsedTripReportData(
            sourceKey: $report->sourceKey, tripDate: $report->tripDate, regionName: $report->regionName,
            landingName: $report->landingName, boatName: $report->boatName, tripTypeName: $report->tripTypeName,
            anglers: $report->anglers, rawFishCountText: $report->rawFishCountText,
            speciesCounts: [$report->speciesCounts[1]], metadata: $report->metadata,
        );
        $diagnostics = $this->diagnostics($payload, new ParsedFishCountCollection(collect([$incomplete]), $parsed->parserVersion, $parsed->format));
        $numeric = collect($diagnostics)->first(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnaccountedNumericTokens);
        $this->assertNotNull($numeric);
        $this->assertSame('20, 30', $numeric->rawValue);
    }

    /** @return array<int, ParserDiagnosticData> */
    private function diagnostics(RawPayloadData $payload, ParsedFishCountCollection $parsed): array
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);

        return collect(app(ParsedReportValidator::class)->validate(new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]), $payload, $parsed))
            ->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)->values()->all();
    }
}
