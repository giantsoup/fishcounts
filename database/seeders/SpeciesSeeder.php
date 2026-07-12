<?php

namespace Database\Seeders;

use App\Models\Species;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SpeciesSeeder extends Seeder
{
    public function run(): void
    {
        $species = ['Yellowtail', 'Bluefin Tuna', 'Yellowfin Tuna', 'Dorado', 'White Seabass', 'Bonito', 'Calico Bass', 'Sand Bass', 'Rockfish', 'Barracuda', 'Sheephead', 'Whitefish', 'Sculpin', 'Lingcod', 'Halibut', 'Cabezon', 'Opah', 'Mako Shark', 'Pacific Mackerel', 'Halfmoon'];
        $offshoreSpecies = ['yellowtail', 'bluefin-tuna', 'yellowfin-tuna', 'dorado', 'opah', 'mako-shark'];

        foreach ($species as $name) {
            $slug = Str::slug($name);
            $speciesModel = Species::query()->firstOrNew(['slug' => $slug]);

            if (! $speciesModel->exists) {
                $speciesModel->environmental_location_profile = in_array($slug, $offshoreSpecies, true)
                    ? 'coronado_islands'
                    : 'san_diego_bight';
            }

            $speciesModel->fill(['name' => $name, 'is_active' => true])->save();
        }
    }
}
