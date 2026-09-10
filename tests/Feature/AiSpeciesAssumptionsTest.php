<?php

namespace Tests\Feature;

use App\DTOs\RawPayloadData;
use App\Enums\SourceType;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Services\Parsing\AiParsedCollectionFactory;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class AiSpeciesAssumptionsTest extends TestCase
{
    /** @param array<string, int> $expected */
    #[DataProvider('reports')]
    public function test_ai_uses_the_same_approved_interpretations(string $counts, string $rawSpecies, int $id, int $quantity, array $expected): void
    {
        $text = "Dolphin Full Day 20 anglers {$counts}";
        [$payload, $raw, $result, $catalog] = $this->fixture($text, $rawSpecies, $id, $quantity);
        $parsed = app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text}", $result, $catalog);
        $this->assertSame($expected, collect($parsed->tripReports->sole()->speciesCounts)->pluck('count', 'speciesName')->all());
        $this->assertSame($text, $parsed->tripReports->sole()->rawFishCountText);
        foreach ($parsed->tripReports->sole()->speciesCounts as $species) {
            $this->assertSame($text, $species->rawText);
        }
    }

    /** @return array<string, array{string, string, int, int, array<string, int>}> */
    public static function reports(): array
    {
        return [
            'implied one' => ['54 Yellowtail and Stripped Marlin.', 'Stripped Marlin', 3, 1, ['Striped Marlin' => 1]],
            'limits context' => ['LIMITS of Yellowtail (12), 17 Yellow.', 'Yellow', 2, 17, ['Yellowfin Tuna' => 17]],
            'zero context' => ['0 Yellowtail, 17 Yellow.', 'Yellow', 2, 17, ['Yellowfin Tuna' => 17]],
            'yellowtail present' => ['12 Yellowtail, 17 Yellow.', 'Yellow', 2, 17, ['Yellowfin Tuna' => 17]],
            'yellowfin present' => ['12 Yellowfin Tuna, 17 Yellow.', 'Yellow', 1, 17, ['Yellowtail' => 17]],
            'neither' => ['12 Bluefin Tuna, 17 Yellow.', 'Yellow', 1, 17, []],
            'both' => ['12 Yellowtail, 5 Yellowfin Tuna, 17 Yellow.', 'Yellow', 1, 17, []],
        ];
    }

    public function test_ai_cannot_assign_unsupported_quantity_to_missing_count(): void
    {
        $text = 'Dolphin Full Day 20 anglers 54 Yellowtail and Stripped Marlin.';
        [$payload, $raw, $result, $catalog] = $this->fixture($text, 'Stripped Marlin', 3, 54);
        $this->expectException(UnexpectedValueException::class);
        app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text}", $result, $catalog);
    }

    public function test_two_trips_by_the_same_boat_cannot_share_yellow_context(): void
    {
        $text = 'Dolphin Full Day 20 anglers 12 Yellowtail. Dolphin PM 20 anglers 17 Yellow.';
        [$payload, $raw, $result, $catalog] = $this->fixture($text, 'Yellow', 2, 17);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('single-boat source evidence');
        app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text}", $result, $catalog);
    }

    public function test_am_and_pm_without_repeated_boat_name_cannot_share_context(): void
    {
        $text = 'Dolphin Full Day 20 anglers AM 12 Yellowtail, PM 17 Yellow.';
        [$payload, $raw, $result, $catalog] = $this->fixture($text, 'Yellow', 2, 17);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('single-boat source evidence');
        app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text}", $result, $catalog);
    }

    public function test_ai_cannot_hide_an_assumption_by_truncating_raw_text(): void
    {
        $text = 'Dolphin Full Day 20 anglers 54 Yellowtail and Stripped Marlin.';
        [$payload, $raw, $result, $catalog] = $this->fixture($text, 'Yellowtail', 1, 54);
        $result['reports'][0]['raw_fish_count_text'] = '54 Yellowtail';
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('single-boat source evidence');
        app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text}", $result, $catalog);
    }

    public function test_ai_cannot_omit_an_inferred_catch(): void
    {
        $text = 'Dolphin Full Day 20 anglers 54 Yellowtail and Stripped Marlin.';
        [$payload, $raw, $result, $catalog] = $this->fixture($text, 'Yellowtail', 1, 54);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('owner-approved species assumption');
        app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text}", $result, $catalog);
    }

    public function test_ai_cannot_omit_contextually_resolved_yellow(): void
    {
        $text = 'Dolphin Full Day 20 anglers 12 Yellowtail, 17 Yellow.';
        [$payload, $raw, $result, $catalog] = $this->fixture($text, 'Yellowtail', 1, 12);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('owner-approved species assumption');
        app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text}", $result, $catalog);
    }

    public function test_another_reports_yellowtail_does_not_supply_context(): void
    {
        $text = 'Dolphin Full Day 20 anglers 17 Yellow.';
        [$payload, $raw, $result, $catalog] = $this->fixture($text, 'Yellow', 2, 17);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('single-boat source evidence');
        app(AiParsedCollectionFactory::class)->make($payload, $raw, "[block:0001] {$text} The Pegasus caught 3 Yellowtail.", $result, $catalog);
    }

    /** @return array{RawScrapePayload, RawPayloadData, array<string, mixed>, array<string, mixed>} */
    private function fixture(string $text, string $species, int $id, int $quantity): array
    {
        $payload = new RawScrapePayload(['target_date' => '2026-08-28']);
        $payload->setRelation('scrapeSource', new ScrapeSource(['source_type' => SourceType::Landing, 'name' => "Fisherman's Landing"]));
        $raw = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-08-28'), 'https://example.test/counts', $text);
        $catalog = [
            'boats' => [['id' => 1, 'name' => 'Dolphin', 'landing_id' => 1]],
            'landings' => [['id' => 1, 'name' => "Fisherman's Landing"]],
            'trip_types' => [['id' => 1, 'name' => 'Full Day']],
            'species' => [['id' => 1, 'name' => 'Yellowtail'], ['id' => 2, 'name' => 'Yellowfin Tuna'], ['id' => 3, 'name' => 'Striped Marlin']],
        ];
        $result = ['reports' => [[
            'source_item_id' => 'block:0001', 'evidence_spans' => [$text],
            'raw_boat_name' => 'Dolphin', 'canonical_boat_id' => 1, 'raw_landing_name' => null, 'canonical_landing_id' => null,
            'raw_trip_type' => 'Full Day', 'canonical_trip_type_id' => 1, 'anglers' => 20, 'raw_fish_count_text' => $text,
            'species_counts' => [['raw_species_name' => $species, 'canonical_species_id' => $id, 'retained_count' => $quantity, 'released_count' => 0, 'evidence_spans' => [$text]]],
        ]]];

        return [$payload, $raw, $result, $catalog];
    }
}
