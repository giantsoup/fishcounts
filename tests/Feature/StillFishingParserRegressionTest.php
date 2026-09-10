<?php

namespace Tests\Feature;

use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\RawPayloadData;
use App\Services\Parsing\GenericFishCountParser;
use App\Services\Parsing\Rules\UnknownAliasRule;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StillFishingParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_50_preserves_every_catch_without_an_unknown_species_diagnostic_or_ai_call(): void
    {
        $this->seed(SpeciesSeeder::class);
        Http::preventStrayRequests();
        Http::fake();

        $paragraph = 'The Dolphin called in with 2 White Seabass, 6 Yellowtail (15 to 20 pounds), 40 Bonito, 24 Barracuda, 45 Calico Bass, 22 Sheephead, 65 Rockfish and still fishing for 11 anglers.';
        $payload = new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-09-10'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: "<p>{$paragraph}</p>",
        );
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $report = $parsed->tripReports->sole();

        $this->assertSame('Dolphin', $report->boatName);
        $this->assertSame("Fisherman's Landing", $report->landingName);
        $this->assertNull($report->tripTypeName);
        $this->assertSame(11, $report->anglers);
        $this->assertSame($paragraph, $report->rawFishCountText);
        $this->assertSame([
            ['White Seabass', 2, 0],
            ['Yellowtail', 6, 0],
            ['Bonito', 40, 0],
            ['Barracuda', 24, 0],
            ['Calico Bass', 45, 0],
            ['Sheephead', 22, 0],
            ['Rockfish', 65, 0],
        ], collect($report->speciesCounts)->map(fn (ParsedSpeciesCountData $count): array => [
            $count->speciesName, $count->count, $count->releasedCount,
        ])->all());

        $findings = app(UnknownAliasRule::class)->inspect(new ParsedReportValidationData(
            payload: $payload,
            parsed: $parsed,
            report: $report,
            reportIndex: 0,
            parserVersion: $parsed->parserVersion,
            format: $parsed->format,
            sourceIdentifier: null,
            sanitizedParagraph: $paragraph,
        ));

        $this->assertFalse(collect($findings)->contains('field', 'species'));
        Http::assertNothingSent();
    }

    /** @param array<string, array{int, int}> $expected */
    #[DataProvider('fishingStatusReports')]
    public function test_fishing_status_boundaries_preserve_counts(string $paragraph, array $expected): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts($paragraph);

        $this->assertSame($expected, $counts->mapWithKeys(fn (ParsedSpeciesCountData $count): array => [
            $count->speciesName => [$count->count, $count->releasedCount],
        ])->all());
    }

    /** @return array<string, array{string, array<string, array{int, int}>}> */
    public static function fishingStatusReports(): array
    {
        return [
            'minimal reproduction' => ['65 Rockfish and still fishing.', ['Rockfish' => [65, 0]]],
            'without conjunction' => ['65 Rockfish still fishing', ['Rockfish' => [65, 0]]],
            'case and whitespace' => ['65 Rockfish AND STILL   FISHING!', ['Rockfish' => [65, 0]]],
            'released catch' => ['65 Rockfish Released and still fishing for 11 anglers.', ['Rockfish' => [0, 65]]],
            'another catch follows' => ['65 Rockfish and still fishing, 2 Yellowtail.', ['Rockfish' => [65, 0], 'Yellowtail' => [2, 0]]],
            'existing so far status' => ['65 Rockfish so far still fishing for 11 anglers.', ['Rockfish' => [65, 0]]],
            'ordinary catch conjunction' => ['65 Rockfish and 2 Yellowtail for 11 anglers.', ['Rockfish' => [65, 0], 'Yellowtail' => [2, 0]]],
        ];
    }
}
