<?php

namespace Database\Seeders;

use App\Jobs\ResearchLimitsRegulationJob;
use Illuminate\Database\Seeder;

class RegulationRuleSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('limits.research_enabled')) {
            return;
        }
        foreach (['mexico', 'california_southern'] as $jurisdiction) {
            foreach (array_keys(config('limits-reviewed.'.$jurisdiction)) as $taxon) {
                ResearchLimitsRegulationJob::dispatch($jurisdiction, $taxon, config('limits-reviewed.effective_until'))->onConnection('database');
            }
        }
    }
}
