<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticType;
use App\Models\RawScrapePayload;
use App\Services\Parsing\GenericFishCountParser;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class October28ParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_dolphin_paragraph_preserves_approximate_and_compact_catches(): void
    {
        $payload = $this->payload();
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $this->assertSame(['Dolphin', 'Pacific Queen'], $parsed->tripReports->pluck('boatName')->all());
        $dolphin = $parsed->tripReports->first();
        $this->assertSame([null, 33], [$dolphin->tripTypeName, $dolphin->anglers]);
        $this->assertSame(['Skipjack' => [143, 0], 'Yellowfin' => [11, 0], 'Bonito' => [42, 0], 'Yellowtail' => [9, 0], 'Sandbass' => [9, 0]], $this->counts($dolphin));
        $this->assertStringContainsString('11 plus Yellowfin. 42 Bonito, and 9Yellowtail', $dolphin->rawFishCountText);
        $this->assertSame(['Yellowtail' => [1, 0], 'Yellowfin Tuna' => [207, 0], 'Wahoo' => [2, 0]], $this->counts($parsed->tripReports[1]));
        $this->assertSame(['3 Day', 25], [$parsed->tripReports[1]->tripTypeName, $parsed->tripReports[1]->anglers]);
    }

    #[DataProvider('missingSpecies')]
    public function test_repaired_catches_have_valid_evidence_and_remain_detectable_when_omitted(string $species): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $payload = $this->payload();
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $rawPayload = new RawScrapePayload(['payload_hash' => hash('sha256', $payload->body)]);
        $validator = app(ParsedReportValidator::class);
        $this->assertSame([], collect($validator->validate($rawPayload, $payload, $parsed))->reject(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnknownAlias)->values()->all());
        $report = $parsed->tripReports->first();
        $missing = new ParsedTripReportData(
            sourceKey: $report->sourceKey, tripDate: $report->tripDate, regionName: $report->regionName,
            landingName: $report->landingName, boatName: $report->boatName, tripTypeName: $report->tripTypeName,
            anglers: $report->anglers, rawFishCountText: $report->rawFishCountText,
            speciesCounts: array_values(array_filter($report->speciesCounts, fn (ParsedSpeciesCountData $count): bool => $count->speciesName !== $species)),
            metadata: $report->metadata,
        );
        $reports = $parsed->tripReports->values();
        $reports[0] = $missing;
        $diagnostics = $validator->validate($rawPayload, $payload, new ParsedFishCountCollection($reports, $parsed->parserVersion, $parsed->format));
        $this->assertTrue(collect($diagnostics)->contains(fn (ParserDiagnosticData $diagnostic): bool => $diagnostic->type === ParserDiagnosticType::UnaccountedNumericTokens));
    }

    public function test_approximation_and_compact_spacing_do_not_turn_metadata_into_catches(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts('The Dolphin on their 1st day of a 3 day trip caught 11 plus Yellowfin, 9Yellowtail and 1 White Seabass 50 plus pounds, using #9 hooks and 9lbs tackle, departing 9:00AM for 33 anglers.');
        $this->assertSame(['Yellowfin' => [11, 0], 'Yellowtail' => [9, 0], 'White Seabass' => [1, 0]], $counts->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => [$count->count, $count->releasedCount]])->all());
    }

    /** @return array<string, array{string}> */
    public static function missingSpecies(): array
    {
        return ['approximate catch' => ['Yellowfin'], 'compact catch sharing a count with Sandbass' => ['Yellowtail']];
    }

    /** @return array<string, array{?int, int}> */
    private function counts(ParsedTripReportData $report): array
    {
        return collect($report->speciesCounts)->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [$count->speciesName => [$count->count, $count->releasedCount]])->all();
    }

    private function payload(): RawPayloadData
    {
        return new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-10-28'), 'https://example.test/counts', <<<'HTML'
            <div><p>10/28/2026<br>
            The Dolphin called in with 143 Skipjack, 11 plus Yellowfin. 42 Bonito, and 9Yellowtail and 9 Sandbass for 33 anglers and still fishing. Captain also reported flat calm seas and sardines for bait.<br>
            The Pacific Queen returned from a 3 day trip with 1 Yellowtail, 207 Yellowfin Tuna and 2 Wahoo for 25 anglers.</p>
            <p>10/27/2026<br>The Dolphin caught 2 Bonito for 4 anglers.</p></div>
            HTML);
    }
}
