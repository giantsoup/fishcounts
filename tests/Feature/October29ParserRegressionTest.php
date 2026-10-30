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

class October29ParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_seaforth_update_preserves_all_trips_without_tackle_or_advertising(): void
    {
        $payload = $this->payload();
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);

        $this->assertSame(['San Diego', 'Sea Watch', 'New Seaforth', 'New Seaforth'], $parsed->tripReports->pluck('boatName')->all());
        $this->assertSame(['Full Day', 'Full Day', '1/2 Day AM', '1/2 Day PM'], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame([35, null, null, null], $parsed->tripReports->pluck('anglers')->all());
        $this->assertSame([
            ['Yellowfin Tuna' => [141, 0], 'Skipjack' => [129, 0]],
            ['Yellowfin Tuna' => [53, 0], 'Dorado' => [1, 0], 'Skipjack Tuna' => [127, 0]],
            ['Yellowfin' => [21, 0], 'Skipjack' => [83, 0]],
            ['Yellowfin' => [45, 0], 'Skipjack Tuna' => [110, 0]],
        ], $parsed->tripReports->map(fn (ParsedTripReportData $report): array => collect($report->speciesCounts)
            ->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => [$count->count, $count->releasedCount]])->all())->all());
        $this->assertSame('The San Diego on their full day bagged 141 Yellowfin Tuna, and 129 Skipjack for their 35 anglers!', $parsed->tripReports[0]->rawFishCountText);
        $this->assertSame('The New Seaforth was back on the Tuna this morning landing 21 Yellowfin and 83 Skipjack!', $parsed->tripReports[2]->rawFishCountText);
        $this->assertSame('They located them again on the afternoon trip landing 45 Yellowfin, and 110 Skipjack Tuna.', $parsed->tripReports[3]->rawFishCountText);
    }

    public function test_repaired_reports_clear_structural_diagnostics_but_missing_reports_remain_detectable(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = $this->payload();
        $stored = new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]);
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $validator = app(ParsedReportValidator::class);
        $diagnostics = collect($validator->validate($stored, $payload, $parsed))->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias);

        $this->assertCount(4, $parsed->tripReports);
        $this->assertSame([], $diagnostics->values()->all());
        $missing = $validator->validate($stored, $payload, new ParsedFishCountCollection($parsed->tripReports->slice(1)->values(), $parsed->parserVersion, $parsed->format));
        $this->assertTrue(collect($missing)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet
            && str_contains($diagnostic->context['sanitized_paragraph'], '141 Yellowfin Tuna')));

        $reports = $parsed->tripReports->map(fn (ParsedTripReportData $report): ParsedTripReportData => new ParsedTripReportData(
            sourceKey: $report->sourceKey, tripDate: $report->tripDate, regionName: $report->regionName,
            landingName: $report->landingName, boatName: $report->boatName, tripTypeName: $report->tripTypeName,
            anglers: $report->anglers, rawFishCountText: $report->rawFishCountText,
            speciesCounts: array_slice($report->speciesCounts, 0, 1), metadata: $report->metadata,
        ));
        $missingCatches = $validator->validate($stored, $payload, new ParsedFishCountCollection($reports, $parsed->parserVersion, $parsed->format));
        foreach (['129', '83', '110'] as $count) {
            $this->assertTrue(collect($missingCatches)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnaccountedNumericTokens
                && in_array($count, $diagnostic->context['evidence']['unaccounted_tokens'], true)));
        }
    }

    public function test_period_continuations_require_completed_catches_and_an_explicit_half_day_heading(): void
    {
        $payload = $this->payload();
        $futureTripBody = str_replace('They located them again on the afternoon trip landing 45 Yellowfin, and 110 Skipjack Tuna.', 'They have an afternoon trip tomorrow with a 20-25lb setup and hooks from #4-2/0.', $payload->body);
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData($payload->sourceKey, $payload->targetDate, $payload->url, $futureTripBody));

        $this->assertSame(['Full Day', 'Full Day', '1/2 Day AM'], $parsed->tripReports->pluck('tripTypeName')->all());
        $this->assertSame([21, 83], collect($parsed->tripReports->last()->speciesCounts)->pluck('count')->all());

        $fullDayBody = str_replace('<h4>New Seaforth Half Day </h4>', '<h4>New Seaforth Full Day</h4>', $payload->body);
        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData($payload->sourceKey, $payload->targetDate, $payload->url, $fullDayBody));

        $this->assertSame(['San Diego', 'Sea Watch'], $parsed->tripReports->pluck('boatName')->all());
    }

    private function payload(): RawPayloadData
    {
        return new RawPayloadData('seaforth_landing', CarbonImmutable::parse('2026-10-29'), 'https://example.test/counts', <<<'HTML'
            <div class='announcement'>
            <h2 class='text-center teal-gradient'>Update for Oct. 29, 2026 5:08 PM</h2>
            <div class='announcement-text'><h3 class='text-center'>Thursday Update</h3><br><h4>San Diego Full Day</h4>
            <ul>
            <li>The <em>San Diego</em> on their full day bagged 141 Yellowfin Tuna, and 129 Skipjack for their 35 anglers! Recommended setups for the <em>San Diego</em> has been a 20lb-25lb setup along with a 30lb-40lb setup for anchovies and sardines. Small jigs have been seeing decent response for the anglers looking to throw artificials.Passports are <b>Not</b> currently required for this trip. </li>
            </ul>
            <h4>Sea Watch Full Day</h4>
            <ul>
            <li>The <em>Sea Watch</em> finished up their full day trip with 53 Yellowfin Tuna, 1 Dorado and 127 Skipjack Tuna. Current sniper style jigs have been getting bit well but also good to have a 20-25lb live bait setup. The <em>Sea Watch</em> has spots available for tomorrow and Saturday! Passports are <b>Not</b> currently required for this trip.</li>
            </ul>
            <h4>New Seaforth Half Day </h4>
            <ul>
            <li>The <em>New Seaforth</em> was back on the Tuna this morning landing 21 Yellowfin and 83 Skipjack! They located them again on the afternoon trip landing 45 Yellowfin, and 110 Skipjack Tuna. The <em>New Seaforth</em> has plenty of room on both of tomorrow's trips! Make those reservations or come on down. A 20-25lb setup would be recommended for these fish along with hooks from #4-2/0. Rod rentals and fishing licenses are available at the landing during check-in.</li>
            </ul></div>
            </div>
            HTML);
    }
}
