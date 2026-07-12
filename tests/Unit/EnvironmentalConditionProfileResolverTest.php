<?php

namespace Tests\Unit;

use App\Models\AlertRule;
use App\Models\Species;
use App\Services\Environmental\EnvironmentalConditionProfileResolver;
use Tests\TestCase;

class EnvironmentalConditionProfileResolverTest extends TestCase
{
    public function test_species_configured_for_offshore_conditions_use_the_coronado_islands_profile(): void
    {
        $rule = new AlertRule;
        $rule->setRelation('species', new Species([
            'slug' => 'yellowtail',
            'environmental_location_profile' => 'coronado_islands',
        ]));

        $this->assertSame('coronado_islands', app(EnvironmentalConditionProfileResolver::class)->resolve($rule));
    }

    public function test_local_species_use_the_default_profile(): void
    {
        $rule = new AlertRule;
        $rule->setRelation('species', new Species(['slug' => 'calico-bass']));

        $this->assertSame('san_diego_bight', app(EnvironmentalConditionProfileResolver::class)->resolve($rule));
    }

    public function test_the_species_profile_is_the_source_of_truth(): void
    {
        $rule = new AlertRule(['settings' => ['environmental_location_profile' => 'san_diego_bight']]);
        $rule->setRelation('species', new Species([
            'slug' => 'yellowtail',
            'environmental_location_profile' => 'coronado_islands',
        ]));

        $this->assertSame('coronado_islands', app(EnvironmentalConditionProfileResolver::class)->resolve($rule));
    }

    public function test_an_invalid_species_profile_falls_back_to_the_default_profile(): void
    {
        $rule = new AlertRule;
        $rule->setRelation('species', new Species([
            'slug' => 'yellowtail',
            'environmental_location_profile' => 'unknown',
        ]));

        $this->assertSame('san_diego_bight', app(EnvironmentalConditionProfileResolver::class)->resolve($rule));
    }
}
