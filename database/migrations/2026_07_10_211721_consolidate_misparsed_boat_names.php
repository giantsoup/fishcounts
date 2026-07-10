<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $consolidations = [
            'Dolphin' => [
                'Am Dolphin',
                'Dolphin Am',
                'Dolphin Am 22 Anglers',
                'Dolphin Am Trip',
                'Dolphin Pm Trip',
                'Dolphin Twilight Trip',
                'Dolphin Twiligiht Trip Last Night',
                'Dolphin Twlight',
                'Fridaythe Dolphin',
                'Pm Dolphin',
                'The Dolphin (Am) Caught 51 Calico Bass (Released 100), 26 Bonito, 32 Rockfish, 6 Sheephead, And 5 Sandbass For',
                'The Dolphin (Am) Trip Caught 32 Calico Bass And 50 Released, 6 Sandbass, 30 Rockfish, 5 Sheephead And 1 White Seabass For',
                'The Dolphin (Pm) Trip Caught 37 Rockfish, 2 Sculpin, 6 Sheephead, 4 Sandbass, 2 Calico (Kelp) Bass, And 1 Halibut For',
                'The Dolphin (Pm) Trip Had 56 Calico Bass (200 Released), 39 Bonito, 1 Cabazon,4 Sheephead, And 1 Yelowtail For',
                'The Dolphin Am',
                'The Dolphin Pm',
                'The Dolphin Pm Returned With 18 Sand Bass, 6 Sculpin, 3 Rock Sole For',
            ],
            'Lucky B' => [
                'Lucky B Caught 10 Yellowtail For',
                'Lucky B Sportfishing',
            ],
            'Tomahawk' => ['Tomahawk Just'],
            'San Diego' => ['Wednesday San Diego'],
            'New Seaforth' => [
                "New Seaforth's Am",
                "New Seaforth's Friday Evening",
                "New Seaforth's Thursday Evening",
            ],
            'Tribute' => [
                'Tribute Finished Up Their Reverse',
                'Tribute Returned This Afternoon From A Reverse',
            ],
            'Pacific Voyager' => [
                'Pacific Voyager Returned This Afternoon From A',
                'The Pacific Voyager Returned This Evening From A',
            ],
            'Voyager' => [
                'Voyager Returned This Evening From A',
                'Voyager Returned Today From A',
            ],
            'Polaris Supreme' => [
                'Polaris Supreme Finished Their',
                'Polaris Supreme Returned This Morning From Their',
            ],
            'Aztec' => ['Aztec Also Returned This Afternoon From A'],
        ];

        DB::transaction(function () use ($consolidations): void {
            foreach ($consolidations as $canonicalName => $variantNames) {
                $canonicalBoatId = DB::table('boats')->where('name', $canonicalName)->value('id');

                if ($canonicalBoatId === null) {
                    if (DB::table('boats')->whereIn('name', $variantNames)->exists()) {
                        throw new RuntimeException("Missing canonical boat [{$canonicalName}].");
                    }

                    continue;
                }

                $variantBoatIds = DB::table('boats')
                    ->whereIn('name', $variantNames)
                    ->where('id', '!=', $canonicalBoatId)
                    ->pluck('id');

                foreach ($variantBoatIds as $variantBoatId) {
                    DB::table('trip_reports')->where('boat_id', $variantBoatId)->update(['boat_id' => $canonicalBoatId]);
                    DB::table('boat_aliases')->where('boat_id', $variantBoatId)->update(['boat_id' => $canonicalBoatId]);

                    foreach (DB::table('alert_rule_boat')->where('boat_id', $variantBoatId)->pluck('alert_rule_id') as $alertRuleId) {
                        DB::table('alert_rule_boat')->updateOrInsert([
                            'alert_rule_id' => $alertRuleId,
                            'boat_id' => $canonicalBoatId,
                        ]);
                    }

                    DB::table('alert_rule_boat')->where('boat_id', $variantBoatId)->delete();
                }

                DB::table('boats')->whereIn('id', $variantBoatIds)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
            }

            $luckyBoatId = DB::table('boats')->where('name', 'Lucky B')->value('id');

            if ($luckyBoatId !== null) {
                $aliasId = DB::table('boat_aliases')
                    ->where('normalized_alias', 'lucky b sportfishing')
                    ->value('id');
                $attributes = [
                    'boat_id' => $luckyBoatId,
                    'alias' => 'Lucky B Sportfishing',
                    'updated_at' => now(),
                ];

                if ($aliasId === null) {
                    DB::table('boat_aliases')->insert($attributes + [
                        'normalized_alias' => 'lucky b sportfishing',
                        'created_at' => now(),
                    ]);
                } else {
                    DB::table('boat_aliases')->where('id', $aliasId)->update($attributes);
                }
            }
        }, attempts: 3);
    }

    /**
     * This correction is intentionally forward-only because reparsed reports
     * and reassigned dependencies cannot be safely reconstructed.
     */
    public function down(): void {}
};
