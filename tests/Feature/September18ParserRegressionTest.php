<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
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

class September18ParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, array{int, int}> $counts */
    #[DataProvider('narratives')]
    public function test_narrative_keeps_boat_trip_and_all_catches(string $paragraph, string $boat, string $trip, int $anglers, array $counts): void
    {
        $this->seed(DatabaseSeeder::class);
        $payload = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-09-18'), 'https://example.test/counts', "<p>9/18/2026<br />\n{$paragraph}<br />\n9/17/2026<br />\nThe Dolphin AM trip caught 10 Rockfish for 20 anglers.</p>");
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $report = $parsed->tripReports->sole();

        $this->assertSame([$boat, $trip, $anglers], [$report->boatName, $report->tripTypeName, $report->anglers]);
        $this->assertSame(strip_tags($paragraph), $report->rawFishCountText);
        $this->assertSame($counts, collect($report->speciesCounts)->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => [$count->count, $count->releasedCount]])->all());
        $this->assertSame([], $this->diagnostics($payload, $parsed));
    }

    /** @return array<string, array{string, string, string, int, array<string, array{int, int}>}> */
    public static function narratives(): array
    {
        return [
            'Dolphin PM had' => [
                'The <strong>Dolphin</strong> on the PM half day had 58 Calico bass (100 released) 2 Sand bass 2 Barracuda 1 Yellowtail 2 Sheephead 8 Rockfish and limits of Bonito (115) for 23 anglers',
                'Dolphin', '1/2 Day PM', 23,
                ['Calico Bass' => [58, 100], 'Sand Bass' => [2, 0], 'Barracuda' => [2, 0], 'Yellowtail' => [1, 0], 'Sheephead' => [2, 0], 'Rockfish' => [8, 0], 'Bonito' => [115, 0]],
            ],
            'Constitution final release before bare duration' => [
                'The <strong>Constitution</strong> returned this morning with 56 Dorado, 62 Yellowfin Tuna (70 released), 5 Yellowtail, and 1 Striped Marlin ( 2 released) for 19 anglers on their 2.5 day.',
                'Constitution', '2.5 Day', 19,
                ['Dorado' => [56, 0], 'Yellowfin Tuna' => [62, 70], 'Yellowtail' => [5, 0], 'Striped Marlin' => [1, 2]],
            ],
            'Dolphin AM had' => [
                'The Dolphin on the AM half day had 3 Calico Bass ( 7 released) for 10 anglers on their 1/2 day.',
                'Dolphin', '1/2 Day AM', 10,
                ['Calico Bass' => [3, 7]],
            ],
        ];
    }

    public function test_party_boat_diagnostics_use_the_same_region_and_preserve_missing_trips(): void
    {
        $this->seed(DatabaseSeeder::class);
        $outside = $this->partyBoatRow('Doghouse', 'Northwest Fishing Charters', 'Edmonds, WA', 6, '12 Silver Salmon');
        $dolphin = $this->partyBoatRow('Dolphin', "Fisherman's Landing", 'San Diego, CA', 23, '58 Calico Bass, 100 Calico Bass Released, 115 Bonito');
        $liberty = $this->partyBoatRow('Liberty', "Fisherman's Landing", 'San Diego, CA', 21, '35 Yellowfin Tuna, 100 Yellowfin Tuna Released');
        $payload = new RawPayloadData('sportfishingreport_landing_pages', CarbonImmutable::parse('2026-09-18'), 'https://example.test/counts', "<div class='panel'><h2>Washington Coast Fish Counts</h2>{$outside}</div><div class='panel'><h2>San Diego Fish Counts</h2>{$dolphin}{$liberty}</div><div class='panel'><h2>Mexico Fish Counts</h2>{$outside}</div>");
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);

        $this->assertSame(['Dolphin', 'Liberty'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame([], $this->diagnostics($payload, $parsed));
        $paragraphs = app(DiagnosticContextFactory::class)->fishCountParagraphs($payload);
        $this->assertStringNotContainsString('Edmonds', implode(' ', $paragraphs));

        $incomplete = new ParsedFishCountCollection($parsed->tripReports->take(1), $parsed->parserVersion, $parsed->format);
        $diagnostics = $this->diagnostics($payload, $incomplete);
        $this->assertCount(1, $diagnostics);
        $this->assertSame(ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet, $diagnostics[0]->type);
        $this->assertStringContainsString('35 Yellowfin Tuna', $diagnostics[0]->context['sanitized_paragraph']);
    }

    private function partyBoatRow(string $boat, string $landing, string $city, int $anglers, string $counts): string
    {
        return <<<HTML
            <div style='background-color: #FFFFFF; padding: 10px; border-top: 1px solid #dedede;'>
                <div class="row">
                    <div class="col-xs-12 col-md-4">
                        <a href="/boat"><b>{$boat}</b></a><br>
                        <a href="/landing">{$landing}</a>
                        <br>{$city}<br class="visible-xs-block visible-sm-block">
                        <br class="visible-xs-block visible-sm-block">
                    </div>
                    <div class="col-xs-3 col-md-2">{$anglers} Anglers</div>
                    <div class="col-xs-3 col-md-2">Full Day Trip</div>
                    <div class="col-xs-3 col-md-1">&nbsp;</div>
                    <div class="col-xs-11 col-md-3">{$counts}</div>
                </div>
            </div>
            HTML;
    }

    /** @return array<int, ParserDiagnosticData> */
    private function diagnostics(RawPayloadData $payload, ParsedFishCountCollection $parsed): array
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);

        return collect(app(ParsedReportValidator::class)->validate(new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]), $payload, $parsed))
            ->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)
            ->values()->all();
    }
}
