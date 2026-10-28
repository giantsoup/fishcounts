<?php

namespace Tests\Feature;

use App\Contracts\Parsing\RegulationResearcher;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\Enums\CatchQuantityBasis;
use App\Models\RawScrapePayload;
use App\Services\Parsing\CatchCountFormatter;
use App\Services\Parsing\LimitsClaimExtractor;
use App\Services\Parsing\LimitsResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LimitsResolutionTest extends TestCase
{
    private function report(?string $trip = '1.5 Day', ?int $anglers = 16, string $text = 'limits of Calico Bass.', ?array $counts = null): ParsedTripReportData
    {
        return new ParsedTripReportData('test', CarbonImmutable::parse('2026-10-07'), 'San Diego', 'Landing', 'Boat', $trip, $anglers, $text, $counts ?? app(LimitsClaimExtractor::class)->extract($text));
    }

    private function resolver(?array $rule = null): LimitsResolver
    {
        $researcher = $this->createMock(RegulationResearcher::class);
        $researcher->method('find')->willReturn($rule);

        return new LimitsResolver(app(LimitsClaimExtractor::class), $researcher);
    }

    #[DataProvider('tripContexts')]
    public function test_trip_context_rounds_down_and_local_wording_overrides_mexico(string $trip, string $text, int $days, string $jurisdiction): void
    {
        $context = $this->resolver()->context($this->report($trip, text: $text));
        $this->assertSame($days, $context['days']);
        $this->assertSame($jurisdiction, $context['jurisdiction']);
    }

    public static function tripContexts(): array
    {
        return [
            ['1/2 Day', '', 1, 'california_southern'], ['3/4 Day', '', 1, 'mexico'], ['Overnight', '', 1, 'mexico'],
            ['1.5 Day', '', 1, 'mexico'], ['2.5 Day', '', 2, 'mexico'], ['3.5 Day', '', 3, 'mexico'],
            ['5 Day', '', 5, 'mexico'], ['Local Full Day', '', 1, 'california_southern'], ['Local 3/4 Day', '', 1, 'california_southern'],
            ['1.5 Day', 'caught in California waters', 1, 'california_southern'], ['Local Full Day', 'caught in Mexican waters', 1, 'mexico'],
            ['Long Range', 'on their 8 Day trip', 8, 'mexico'], ['Long Range', '', 1, 'mexico'],
            'Mexican destination in a short-trip label' => ['Mexico 1/2 Day', '', 1, 'mexico'],
            'Catalina destination in the label' => ['Catalina Full Day', '', 1, 'california_southern'],
            'San Clemente destination in the label' => ['San Clemente Island Overnight', '', 1, 'california_southern'],
            'explicit country overrides local wording' => ['Local Full Day in Mexican waters', '', 1, 'mexico'],
            'US waters override duration' => ['2.5 Day', 'caught in U.S. waters', 2, 'california_southern'],
            'full US country name overrides duration' => ['3/4 Day', 'caught in United States waters', 1, 'california_southern'],
            'Coronado trip label' => ['Full Day Coronado Islands', '', 1, 'mexico'],
            'offshore is a duration assumption' => ['Full Day Offshore', '', 1, 'mexico'],
            'permit wording is not catch location' => ['Local Full Day', 'No Mexico fishing permit is needed for this trip.', 1, 'california_southern'],
        ];
    }

    public function test_missing_inputs_and_unavailable_rules_keep_a_null_quantity(): void
    {
        foreach ([$this->report(null), $this->report(anglers: null), $this->report(anglers: 0), $this->report()] as $report) {
            $result = $this->resolver()->resolve($report, new RawScrapePayload(['payload_hash' => 'test']), false)->speciesCounts[0];
            $this->assertNull($result->count);
            $this->assertSame(CatchQuantityBasis::LimitsUnquantified, $result->quantityBasis);
        }
    }

    public function test_verified_rule_estimate_has_reproducible_evidence_and_caps(): void
    {
        $rule = ['daily' => 5, 'max_days' => 3, 'aggregates' => [], 'applicability_verified' => true, 'snapshot_hash' => 'fixture'];
        foreach (['1.5 Day' => 80, '2.5 Day' => 160, '8 Day' => 240] as $trip => $quantity) {
            $report = $this->resolver($rule)->resolve($this->report($trip), new RawScrapePayload(['payload_hash' => 'test']), false);
            $count = $report->speciesCounts[0];
            $this->assertSame($quantity, $count->count);
            $this->assertSame(CatchQuantityBasis::LimitsEstimated, $count->quantityBasis);
            $this->assertSame('fixture', $count->evidence['rule']['snapshot_hash']);
            $this->assertNotEmpty($count->evidence['assumptions']);
        }
    }

    public function test_explicit_and_corroborated_quantities_are_never_clamped(): void
    {
        foreach ([CatchQuantityBasis::Explicit, CatchQuantityBasis::Corroborated] as $basis) {
            $report = $this->report(counts: [new ParsedSpeciesCountData('Calico Bass', 999, quantityBasis: $basis, isLimits: true)]);
            $result = $this->resolver(['daily' => 1])->resolve($report, new RawScrapePayload, false)->speciesCounts[0];
            $this->assertSame(999, $result->count);
            $this->assertSame($basis, $result->quantityBasis);
        }
    }

    public function test_shared_bags_subtract_explicit_counts_and_do_not_allocate_multiple_unknowns(): void
    {
        $rule = ['daily' => 5, 'max_days' => 3, 'applicability_verified' => true, 'aggregates' => [['daily' => 5, 'weights' => ['kelp_bass' => 1, 'barred_sand_bass' => 1]]]];
        $report = $this->report(counts: [...app(LimitsClaimExtractor::class)->extract('limits of Calico Bass'), new ParsedSpeciesCountData('Sand Bass', 20)]);
        $this->assertSame(60, $this->resolver($rule)->resolve($report, new RawScrapePayload, false)->speciesCounts[0]->count);
        $report = $this->report(text: 'limits of Calico Bass, limits of Sand Bass.');
        $this->assertSame([null, null], array_column($this->resolver($rule)->resolve($report, new RawScrapePayload, false)->speciesCounts, 'count'));
    }

    public function test_partial_limits_and_prohibitions_do_not_become_positive_or_zero_estimates(): void
    {
        $rule = ['daily' => 5, 'max_days' => 3, 'aggregates' => [], 'applicability_verified' => true];
        $this->assertNull($this->resolver($rule)->resolve($this->report(text: 'near limits of Calico Bass'), new RawScrapePayload, false)->speciesCounts[0]->count);
        $partial = app(LimitsClaimExtractor::class)->extract('near limits of Calico Bass')[0];
        $this->assertSame('Near Limits Calico Bass', app(CatchCountFormatter::class)->component($partial->component()));
        $this->assertNull($this->resolver([...$rule, 'prohibited' => true])->resolve($this->report(), new RawScrapePayload, false)->speciesCounts[0]->count);
    }

    public function test_explicit_limits_marker_survives_source_grammar_normalization(): void
    {
        $count = new ParsedSpeciesCountData('Bonito', 85);
        $result = $this->resolver()->resolve($this->report(text: 'LIMITS (85) of Bonito for 17 anglers.', counts: [$count]), new RawScrapePayload, false)->speciesCounts[0];
        $this->assertSame(85, $result->count);
        $this->assertTrue($result->isLimits);
        $this->assertSame(CatchQuantityBasis::Explicit, $result->quantityBasis);
    }

    public function test_known_subset_uses_only_its_anglers_and_released_limits_remain_qualitative(): void
    {
        $rule = ['daily' => 5, 'max_days' => 3, 'aggregates' => [], 'applicability_verified' => true];
        $result = $this->resolver($rule)->resolve($this->report(text: 'limits of Calico Bass for 8 anglers.'), new RawScrapePayload, false);
        $this->assertSame(40, $result->speciesCounts[0]->count);
        $released = $this->resolver($rule)->resolve($this->report(text: 'limits of Calico Bass released.'), new RawScrapePayload, false);
        $this->assertNull($released->speciesCounts[0]->count);
        $this->assertSame(0, $released->speciesCounts[0]->releasedCount);
    }

    public function test_contradictory_locations_remain_unresolved(): void
    {
        $this->assertNull($this->resolver()->context($this->report(text: 'caught in Mexican waters and California waters')));
        $this->assertNull($this->resolver()->context($this->report('Mexico Full Day', text: 'caught in California waters')));
    }
}
