<?php

namespace Database\Factories;

use App\Models\RegulationRuleSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegulationRuleSnapshot> */
class RegulationRuleSnapshotFactory extends Factory
{
    public function definition(): array
    {
        $rule = ['jurisdiction' => 'mexico', 'taxon' => 'kelp_bass', 'effective_from' => '2026-10-01', 'effective_until' => '2026-10-27', 'daily' => 5, 'max_days' => 3, 'aggregates' => [], 'applicability_verified' => true, 'sources' => [['url' => 'https://dof.gob.mx/nota_detalle_popup.php?codigo=5323155', 'content_hash' => hash('sha256', 'fixture')]]];

        return ['snapshot_hash' => RegulationRuleSnapshot::fingerprint($rule), 'jurisdiction' => $rule['jurisdiction'], 'taxon' => $rule['taxon'], 'effective_from' => $rule['effective_from'], 'effective_until' => $rule['effective_until'], 'rule' => $rule, 'verified_at' => now(), 'retrieved_at' => now()];
    }
}
