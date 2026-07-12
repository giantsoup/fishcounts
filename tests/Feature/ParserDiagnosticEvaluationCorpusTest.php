<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripTypeAlias;
use App\Services\Parsing\ParsedReportValidator;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use JsonException;
use Tests\TestCase;

class ParserDiagnosticEvaluationCorpusTest extends TestCase
{
    use RefreshDatabase;

    /** @throws JsonException */
    public function test_all_approved_regressions_are_detected_and_clean_false_positives_stay_below_five_percent(): void
    {
        config()->set('fish.parsing.diagnostics.suspicious_enabled', true);
        $this->seed(DatabaseSeeder::class);
        $this->restoreHistoricalUnknownAliases();
        $corpus = json_decode(
            file_get_contents(base_path('tests/Fixtures/Parsing/evaluation-corpus-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $cleanFixtureCount = 0;
        $cleanFalsePositiveCount = 0;

        foreach ($corpus['fixtures'] as $fixture) {
            $storedPayload = $this->storedPayload($fixture);
            $rawPayload = new RawPayloadData(
                sourceKey: $fixture['source']['source_key'],
                targetDate: CarbonImmutable::parse($storedPayload->target_date),
                url: $fixture['source']['url'],
                body: '<p>'.$fixture['input']['sanitized_text'].'</p>',
            );
            $parsed = $this->parsedResult($fixture);
            $actualTypes = collect(app(ParsedReportValidator::class)->validate($storedPayload, $rawPayload, $parsed))
                ->pluck('type.value')
                ->unique()
                ->values();
            $expectedTypes = collect($fixture['expected']['diagnostics'])->pluck('type')->unique()->values();

            if ($expectedTypes->isEmpty()) {
                $cleanFixtureCount++;
                $cleanFalsePositiveCount += $actualTypes->isNotEmpty() ? 1 : 0;
                $this->assertSame([], $actualTypes->all(), "Clean fixture [{$fixture['id']}] produced diagnostics.");

                continue;
            }

            foreach ($expectedTypes as $expectedType) {
                $this->assertTrue(
                    $actualTypes->contains($expectedType),
                    "Regression fixture [{$fixture['id']}] did not produce [{$expectedType}]. Actual: {$actualTypes->implode(', ')}",
                );
            }
        }

        $this->assertGreaterThan(0, $cleanFixtureCount);
        $this->assertLessThan(0.05, $cleanFalsePositiveCount / $cleanFixtureCount);
    }

    /** @param array<string, mixed> $fixture */
    private function parsedResult(array $fixture): ParsedFishCountCollection
    {
        $reports = collect($fixture['input']['deterministic_parse']['reports'])
            ->map(fn (array $report): ParsedTripReportData => $this->report($fixture, $report));

        if ($reports->isEmpty()) {
            return new ParsedFishCountCollection(
                collect(),
                $fixture['source']['parser_version'],
                $fixture['source']['format'],
            );
        }

        return new ParsedFishCountCollection(
            $reports,
            $fixture['source']['parser_version'],
            $fixture['source']['format'],
        );
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $report
     */
    private function report(array $fixture, array $report): ParsedTripReportData
    {
        $counts = collect($report['counts'] ?? []);

        if ($fixture['id'] === 'unknown-legitimate-species-alias-bontio') {
            $counts->push(['species' => 'Bontio', 'retained' => 2, 'released' => 0]);
        }

        if ($fixture['id'] === 'new-species-candidate-pacific-mackerel') {
            $counts->prepend(['species' => 'Pacific Mackerel', 'retained' => 31, 'released' => 0]);
        }

        if ($fixture['id'] === 'prose-captured-as-species-returned-with') {
            $counts = collect([['species' => 'Returned With', 'retained' => 43, 'released' => 0]]);
        }

        if ($fixture['id'] === 'fractional-three-quarter-day-not-a-fish-count') {
            $counts = collect([['species' => 'Day Trip Finished With', 'retained' => 4, 'released' => 0]]);
        }

        $tripType = $report['trip_type'] ?? null;

        if ($fixture['id'] === 'six-hour-trip-type-alias') {
            $tripType = '6 Hour';
        }

        return new ParsedTripReportData(
            sourceKey: $fixture['source']['source_key'],
            tripDate: CarbonImmutable::parse('2026-07-12'),
            regionName: 'San Diego',
            landingName: null,
            boatName: $report['boat'] ?? null,
            tripTypeName: $tripType,
            anglers: $report['anglers'] ?? null,
            rawFishCountText: $fixture['input']['sanitized_text'],
            speciesCounts: $counts
                ->map(function (array $count): ParsedSpeciesCountData {
                    $rawText = collect([
                        ($count['retained'] ?? 0) > 0 ? "{$count['retained']} {$count['species']}" : null,
                        ($count['released'] ?? 0) > 0 ? "{$count['released']} {$count['species']} Released" : null,
                    ])->filter()->implode(', ');

                    return new ParsedSpeciesCountData(
                        speciesName: $count['species'],
                        count: $count['retained'] ?? 0,
                        releasedCount: $count['released'] ?? 0,
                        rawText: $rawText,
                    );
                })
                ->all(),
            metadata: [
                'parser' => $fixture['source']['parser_version'],
                'format' => $fixture['source']['format'],
            ],
        );
    }

    /** @param array<string, mixed> $fixture */
    private function storedPayload(array $fixture): RawScrapePayload
    {
        $source = ScrapeSource::query()->firstOrCreate(
            ['slug' => $fixture['source']['source_key']],
            [
                'name' => Str::headline($fixture['source']['source_key']),
                'source_type' => SourceType::Landing,
                'base_url' => $fixture['source']['url'],
            ],
        );
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-12',
        ]);
        $this->ensureBoatsExist($fixture);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-12',
            'url' => $fixture['source']['url'],
            'payload' => '<p>'.$fixture['input']['sanitized_text'].'</p>',
            'payload_hash' => hash('sha256', $fixture['id']),
            'fetched_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function ensureBoatsExist(array $fixture): void
    {
        $landing = Landing::query()->firstOrFail();

        foreach ($fixture['input']['deterministic_parse']['reports'] as $report) {
            $boatName = $report['boat'] ?? null;

            if (! is_string($boatName) || $boatName === '' || $fixture['id'] === 'sentence-fragment-captured-as-boat') {
                continue;
            }

            Boat::query()->firstOrCreate(
                ['slug' => Str::slug($boatName)],
                ['landing_id' => $landing->id, 'name' => $boatName],
            );
        }
    }

    private function restoreHistoricalUnknownAliases(): void
    {
        SpeciesAlias::query()->where('normalized_alias', 'bontio')->delete();
        Species::query()->where('slug', 'pacific-mackerel')->delete();
        TripTypeAlias::query()->where('normalized_alias', '6 hour')->delete();
    }
}
