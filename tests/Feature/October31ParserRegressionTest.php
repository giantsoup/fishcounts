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
use Tests\TestCase;

class October31ParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_seaforth_sections_preserve_all_five_reports_and_unquantified_limits(): void
    {
        $this->seed(DatabaseSeeder::class);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($this->seaforthPayload());

        $this->assertSame(['Tribute', 'Aztec', 'San Diego', 'New Seaforth', 'New Seaforth'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame(['1.5 Day', '2.5 Day', 'Full Day', '1/2 Day AM', '1/2 Day PM'], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame([null, null, null, null, 38], $parsed->tripReports->pluck('anglers')->all());
        $this->assertSame([
            ['Yellowfin Tuna' => [30, 0], 'Skipjack Tuna' => [40, 0], 'Dorado' => [null, 0]],
            ['Yellowfin Tuna' => [37, 0], 'Skipjack' => [148, 0], 'Yellowtail' => [5, 0], 'Bonito' => [10, 0]],
            ['Yellowfin Tuna' => [127, 0], 'Skipjack' => [26, 0], 'Dorado' => [1, 0], 'Bullet Tuna' => [53, 0]],
            ['Yellowfin' => [65, 0], 'Skipjack' => [64, 0]],
            ['Yellowfin' => [71, 0], 'Skipjack Tuna' => [47, 0]],
        ], $parsed->tripReports->map(fn (ParsedTripReportData $report): array => $this->counts($report))->all());
        $this->assertSame([], $this->structuralDiagnostics($this->seaforthPayload(), $parsed));
        $this->assertTrue(collect($parsed->tripReports->first()->speciesCounts)->firstWhere('speciesName', 'Dorado')->isLimits);
        $this->assertSame('The PM 1/2 day for the New Seaforth bagged 71 Yellowfin, and 47 Skipjack Tuna for their 38 anglers!', $parsed->tripReports->last()->rawFishCountText);
    }

    public function test_ai_trip_diagnostics_use_separate_source_reports_and_detect_an_omitted_pm_trip(): void
    {
        $this->seed(DatabaseSeeder::class);
        $payload = $this->seaforthPayload();
        $reports = collect([
            new ParsedTripReportData('seaforth_landing', $payload->targetDate, 'San Diego', 'Seaforth Sportfishing', 'New Seaforth', null, null,
                'The New Seaforth was back on the Tuna this morning landing 65 Yellowfin and 64 Skipjack!',
                [new ParsedSpeciesCountData('Yellowfin', 65), new ParsedSpeciesCountData('Skipjack', 64)], ['format' => 'ai-structured-output']),
            new ParsedTripReportData('seaforth_landing', $payload->targetDate, 'San Diego', 'Seaforth Sportfishing', 'New Seaforth', '1/2 Day PM', 38,
                'The PM 1/2 day for the New Seaforth bagged 71 Yellowfin, and 47 Skipjack Tuna for their 38 anglers!',
                [new ParsedSpeciesCountData('Yellowfin', 71), new ParsedSpeciesCountData('Skipjack Tuna', 47)], ['format' => 'ai-structured-output']),
        ]);
        $payload = new RawPayloadData($payload->sourceKey, $payload->targetDate, $payload->url, substr($payload->body, strpos($payload->body, '<h4>New Seaforth')));
        $parsed = new ParsedFishCountCollection($reports, 'ai-primary-v7', 'ai-structured-output');
        $this->assertSame([], $this->structuralDiagnostics($payload, $parsed));
        $missing = $this->structuralDiagnostics($payload, new ParsedFishCountCollection($reports->take(1), $parsed->parserVersion, $parsed->format));
        $this->assertTrue(collect($missing)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet
            && str_contains($diagnostic->context['sanitized_paragraph'], '71 Yellowfin')));
    }

    public function test_fishermans_catches_survive_tackle_units_and_omitted_catches_with_the_same_numbers_remain_detectable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $payload = $this->fishermansPayload();
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(['Dolphin', 'Pacific Queen'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame(['1/2 Day AM', '2 Day'], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame([40, 30], $parsed->tripReports->pluck('anglers')->all());
        $this->assertSame(['Barracuda' => [82, 0], 'Bonito' => [48, 0], 'Yellowtail' => [2, 0], 'Skipjack Tuna' => [22, 0], 'Rockcod' => [39, 0], 'Lingcod' => [1, 0]], $this->counts($parsed->tripReports->first()));
        $this->assertSame(['Yellowfin Tuna' => [76, 0], 'Wahoo' => [2, 0], 'Marlin' => [1, 0]], $this->counts($parsed->tripReports->last()));
        $this->assertSame([], $this->structuralDiagnostics($payload, $parsed));

        $body = str_replace('2 Wahoo,', '100 Bonito, 150 Rockfish, 350 Calico Bass Released, 2 Wahoo,', $payload->body);
        $missingPayload = new RawPayloadData($payload->sourceKey, $payload->targetDate, $payload->url, $body);
        $reports = $parsed->tripReports->map(fn (ParsedTripReportData $report): ParsedTripReportData => new ParsedTripReportData(
            sourceKey: $report->sourceKey, tripDate: $report->tripDate, regionName: $report->regionName,
            landingName: $report->landingName, boatName: $report->boatName, tripTypeName: $report->tripTypeName,
            anglers: $report->anglers, rawFishCountText: str_replace('2 Wahoo,', '100 Bonito, 150 Rockfish, 350 Calico Bass Released, 2 Wahoo,', $report->rawFishCountText),
            speciesCounts: $report->speciesCounts, metadata: $report->metadata,
        ));
        $diagnostics = $this->structuralDiagnostics($missingPayload, new ParsedFishCountCollection($reports, $parsed->parserVersion, $parsed->format));
        foreach (['100', '150', '350'] as $count) {
            $this->assertTrue(collect($diagnostics)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnaccountedNumericTokens
                && in_array($count, $diagnostic->context['evidence']['unaccounted_tokens'], true)), $count);
        }
    }

    /** @return array<string, array{?int, int}> */
    private function counts(ParsedTripReportData $report): array
    {
        return collect($report->speciesCounts)->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => [$count->count, $count->releasedCount]])->all();
    }

    /** @return array<int, ParserDiagnosticData> */
    private function structuralDiagnostics(RawPayloadData $payload, ParsedFishCountCollection $parsed): array
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);

        return collect(app(ParsedReportValidator::class)->validate(new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]), $payload, $parsed))
            ->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)->values()->all();
    }

    private function seaforthPayload(): RawPayloadData
    {
        return new RawPayloadData('seaforth_landing', CarbonImmutable::parse('2026-10-31'), 'https://example.test/counts', <<<'HTML'
            <div class='announcement'><h2>Update for Oct. 31, 2026 6:03 PM</h2>
            <div class='announcement-text'><h3>Saturday Update</h3><br><h4>Saturday Updates</h4>
            <ul><li>The <em>Tribute</em> checked in this evening from their 1.5 day trip with limits of dorado, 30 Yellowfin Tuna, and 40 Skipjack Tuna. </li></ul>
            <h4>Saturday Returning Trips</h4>
            <ul><li>The <em>Aztec</em> returned from their 2.5 Day trip with 37 Yellowfin tuna, 148 skipjack, 5 Yellowtail and 10 bonito.</li></ul>
            <h4>San Diego Full Day</h4>
            <ul><li>The <em>San Diego</em> on their full day reported in with 127 Yellowfin Tuna, 26 Skipjack, 1 Dorado, and 53 Bullet Tuna. Passports are <b>Not</b> currently required for this trip. Recommended setups for the <em>San Diego</em> has been a 20lb-25lb setup along with a 30lb-40lb setup for anchovies and sardines. Small jigs have been seeing decent response for the anglers looking to throw artificials.</li></ul>
            <h4>New Seaforth Half Day </h4>
            <ul><li>The <em>New Seaforth</em> was back on the Tuna this morning landing 65 Yellowfin and 64 Skipjack! They have plenty of space for Monday's AM Trip! The PM 1/2 day for the <em>New Seaforth</em> bagged 71 Yellowfin, and 47 Skipjack Tuna for their 38 anglers! Make those reservations or come on down. A 20-25lb setup would be recommended for these fish along with hooks from #4-2/0. Rod rentals and fishing licenses are available at the landing during check-in.</li></ul></div></div>
            HTML);
    }

    private function fishermansPayload(): RawPayloadData
    {
        return new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-10-31'), 'https://example.test/counts', <<<'HTML'
            <p>10/31/2026<br>
            The Dolphin AM trip ended up with 82 Barracuda, 48 Bonito up to 12lbs, and 2 Yellowtail, 22 Skipjack Tuna, 39 Rockcod, 1 Lingcod for 40 angler on their morning trip. They also reported great conditions on the water.<br>
            The Pacific Queen is up to 76 Yellowfin Tuna (mostly 20 to 40 lbs.) 2 Wahoo, and 1 Marlin for 30 anglers on a 2 day trip. Also, they reported beautiful, flat calm weather to start off their day. They recommended yoyo jigs and colt snipers 100-150 gram fished yoyo style in 350+ feet of water.</p>
            <p>10/30/2026<br>The Dolphin caught 10 Bonito for 5 anglers.</p>
            HTML);
    }
}
