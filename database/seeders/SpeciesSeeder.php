<?php

namespace Database\Seeders;

use App\Models\Species;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SpeciesSeeder extends Seeder
{
    public function run(): void
    {
        $species = ['Yellowtail', 'Bluefin Tuna', 'Yellowfin Tuna', 'Dorado', 'White Seabass', 'Bonito', 'Calico Bass', 'Sand Bass', 'Rockfish', 'Barracuda', 'Sheephead', 'Whitefish', 'Sculpin', 'Lingcod', 'Halibut'];

        foreach ($species as $name) {
            Species::query()->updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_active' => true]);
        }
    }
}
