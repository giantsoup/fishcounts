<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use App\Enums\CatchQuantityBasis;
use App\Services\Parsing\GenericFishCountParser;
use App\Services\Parsing\LimitsClaimExtractor;
use App\Services\Parsing\Rules\ExtractedValueSourceSpanMismatchRule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LimitsInferenceTest extends TestCase
{
    #[DataProvider('claims')]
    public function test_limits_are_preserved_without_inventing_a_number(string $text, string $species, bool $full): void
    {
        $claim = app(LimitsClaimExtractor::class)->extract($text)[0];
        $this->assertSame($species, $claim->speciesName);
        $this->assertNull($claim->count);
        $this->assertSame(CatchQuantityBasis::LimitsUnquantified, $claim->quantityBasis);
        $this->assertSame($full, $claim->evidence['full_limits']);
        $this->assertStringContainsString($claim->rawText, $text);
    }

    public static function claims(): array
    {
        return [
            ['caught limits of reds.', 'Red Rock Fish', true],
            ['Limits Bonito, 17 Yellowtail', 'Bonito', true],
            ['boat limits of Calico Bass on their 3/4 Day trip', 'Calico Bass', true],
            ['near limits of reds.', 'Red Rock Fish', false],
            ['limits of Bonito for some anglers.', 'Bonito', false],
            ['caught limits of Bonito in California waters.', 'Bonito', true],
            ['limits of Calico Bass off Catalina.', 'Calico Bass', true],
            ['limits of reds at Coronado Islands.', 'Red Rock Fish', true],
        ];
    }

    public function test_generic_parser_preserves_limits_alongside_explicit_catches(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts('17 Yellowtail and limits of Calico Bass, Limits Bonito.');
        $this->assertSame(['Yellowtail' => 17, 'Calico Bass' => null, 'Bonito' => null], $counts->pluck('count', 'speciesName')->all());
    }

    public function test_released_numbers_do_not_turn_unknown_retained_limits_into_zero(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts('limits of Calico Bass, 20 Calico Bass Released.');
        $this->assertNull($counts->sole()->count);
        $this->assertSame(20, $counts->sole()->releasedCount);
    }

    public function test_limits_quantities_still_require_exact_released_count_evidence(): void
    {
        $date = CarbonImmutable::parse('2026-10-07');
        $text = 'limits of Calico Bass, 20 Calico Bass Released for 25 anglers on their 3/4 Day trip.';
        foreach ([CatchQuantityBasis::LimitsUnquantified, CatchQuantityBasis::Corroborated, CatchQuantityBasis::LimitsEstimated] as $basis) {
            $report = new ParsedTripReportData('test', $date, 'San Diego', 'Landing', 'Dolphin', '3/4 Day', 25, $text, [
                new ParsedSpeciesCountData('Calico Bass', $basis === CatchQuantityBasis::LimitsUnquantified ? null : 75, 999, quantityBasis: $basis, isLimits: true),
            ]);
            $data = new ParsedReportValidationData(new RawPayloadData('test', $date, 'https://example.test', $text), new ParsedFishCountCollection(collect([$report])), $report, 0, 'test', 'narrative-list-item', null, $text);
            $findings = app(ExtractedValueSourceSpanMismatchRule::class)->inspect($data);
            $this->assertCount(1, $findings, $basis->value);
            $this->assertSame(20, $findings[0]->evidence['mismatches'][0]['source_released']);
        }
    }

    public function test_zero_releases_do_not_establish_retained_zero_for_limits(): void
    {
        foreach (['0 Calico Bass Released.', '0 Calico Bass Released, 20 Calico Bass Released.', '0 Calico Bass (Released).'] as $released) {
            $counts = app(GenericFishCountParser::class)->parseSpeciesCounts('limits of Calico Bass, '.$released);
            $this->assertNull($counts->sole()->count, $released);
        }
    }
}
