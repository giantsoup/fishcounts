<?php

namespace Tests\Feature;

use App\Services\Parsing\GenericFishCountParser;
use App\Services\Parsing\SpeciesCountAssumptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpeciesCountAssumptionsTest extends TestCase
{
    /** @param array<string, int> $expected */
    #[DataProvider('counts')]
    public function test_owner_approved_count_interpretations(string $text, array $expected): void
    {
        $this->assertSame($expected, app(GenericFishCountParser::class)->parseSpeciesCounts($text)->pluck('count', 'speciesName')->all());
    }

    public function test_unnumbered_prose_is_not_a_species_assumption(): void
    {
        $text = '10 Yellowtail and fishing for Tuna.';
        $this->assertSame($text, app(SpeciesCountAssumptions::class)->normalize($text));
    }

    public function test_deterministic_policy_does_not_share_context_between_trips(): void
    {
        $policy = app(SpeciesCountAssumptions::class);
        $this->assertNull($policy->yellowSpecies('Dolphin AM 12 Yellowtail. Dolphin PM 17 Yellow.'));
        $this->assertSame('Yellowfin Tuna', $policy->yellowSpecies('Dolphin AM Half Day 12 Yellowtail, 17 Yellow.'));
    }

    public function test_released_yellow_keeps_its_release_status(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts('4 Yellowtail, 17 Yellow Released.');
        $this->assertSame(0, $counts->firstWhere('speciesName', 'Yellowfin Tuna')->count);
        $this->assertSame(17, $counts->firstWhere('speciesName', 'Yellowfin Tuna')->releasedCount);
    }

    /** @return array<string, array{string, array<string, int>}> */
    public static function counts(): array
    {
        return [
            'Islander' => ['54 Yellowtail and Stripped Marlin.', ['Yellowtail' => 54, 'Striped Marlin' => 1]],
            'missing plural quantity' => ['54 Yellowtail and Striped Marlins.', ['Yellowtail' => 54, 'Striped Marlin' => 1]],
            'other named species' => ['5 Bonito and Dorado.', ['Bonito' => 5, 'Dorado' => 1]],
            'explicit quantity' => ['54 Yellowtail and 3 Striped Marlin.', ['Yellowtail' => 54, 'Striped Marlin' => 3]],
            'zero context' => ['0 Yellowtail, 17 Yellow.', ['Yellowtail' => 0, 'Yellowfin Tuna' => 17]],
            'misspelled plural' => ['54 Yellowtail and Stripped Marlins.', ['Yellowtail' => 54, 'Striped Marlin' => 1]],
            'explicit zero' => ['54 Yellowtail and 0 Striped Marlin.', ['Yellowtail' => 54, 'Striped Marlin' => 0]],
            'prose' => ['54 Yellowtail and hooked many more.', ['Yellowtail' => 54]],
            'Aztec excluded' => ['92 Bluefin tuna(limits), 17 Yellow and 11 Dorado.', ['Bluefin Tuna' => 92, 'Dorado' => 11]],
            'yellowtail context' => ['12 Yellowtail, 17 Yellow, 3 Dorado.', ['Yellowtail' => 12, 'Yellowfin Tuna' => 17, 'Dorado' => 3]],
            'yellowfin context' => ['17 Yellow, 12 Yellowfin Tuna.', ['Yellowtail' => 17, 'Yellowfin Tuna' => 12]],
            'both named' => ['12 Yellowtail, 4 Yellowfin Tuna, 17 Yellow.', ['Yellowtail' => 12, 'Yellowfin Tuna' => 4]],
            'neither named' => ['17 Yellow, 3 Bonito.', ['Bonito' => 3]],
            'yellowtail rockfish is not yellowtail' => ['2 Yellowtail Rockfish, 17 Yellow.', ['Yellowtail Rockfish' => 2]],
            'uncounted name is not context' => ['3 Dorado and Yellowtail, 17 Yellow.', ['Dorado' => 3, 'Yellowtail' => 1]],
            'trailing recommendation is not context' => ['17 Yellow, 3 Bonito. Target Yellowtail tomorrow.', ['Bonito' => 3]],
        ];
    }
}
