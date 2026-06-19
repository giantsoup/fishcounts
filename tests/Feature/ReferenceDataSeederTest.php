<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\SpeciesAlias;
use App\Models\TripTypeAlias;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeders_create_expected_aliases_and_default_rule_filters(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rule = AlertRule::query()->where('name', 'Local Yellowtail')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['1/2 Day', '1/2 Day AM', '1/2 Day PM', '3/4 Day', 'Full Day', 'Full Day Coronado Islands'],
            $rule->tripTypes()->pluck('name')->all(),
        );

        $this->assertSame('1/2 Day', TripTypeAlias::query()->where('normalized_alias', 'half day')->firstOrFail()->tripType->name);
        $this->assertSame('Sand Bass', SpeciesAlias::query()->where('normalized_alias', 'sandbass')->firstOrFail()->species->name);
        $this->assertSame('Rockfish', SpeciesAlias::query()->where('normalized_alias', 'vermillion rockfish')->firstOrFail()->species->name);
    }
}
