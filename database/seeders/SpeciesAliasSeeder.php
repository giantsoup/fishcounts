<?php

namespace Database\Seeders;

use App\Models\Species;
use App\Models\SpeciesAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SpeciesAliasSeeder extends Seeder
{
    public function run(): void
    {
        $aliases = [
            'yellowtail' => ['YT', 'Yellows', 'Yelowtail'],
            'bluefin-tuna' => ['Bluefin', 'BFT', 'Bleufin Tuna'],
            'yellowfin-tuna' => ['Yellowfin', 'YFT'],
            'bonito' => ['Bontio'],
            'calico-bass' => ['Calicos'],
            'sand-bass' => ['Sandbass', 'Barred Sand Bass'],
            'rockfish' => ['Assorted Rockfish', 'Misc Rockfish', 'Misc. Rockfish', 'Mixed Rockfish', 'Reds', 'Vermilion Rockfish', 'Vermillion Rockfish', 'Vermillion Red Rockfish', 'Vermilliion Reds', 'Red Rockfish'],
            'barracuda' => ['Baracuda'],
            'lingcod' => ['Lings'],
            'cabezon' => ['Cabazon'],
            'white-seabass' => ['White Sea Bass'],
        ];

        foreach ($aliases as $slug => $values) {
            $species = Species::query()->where('slug', $slug)->firstOrFail();

            foreach ($values as $alias) {
                SpeciesAlias::query()->updateOrCreate(
                    ['normalized_alias' => Str::of($alias)->lower()->squish()->toString()],
                    ['species_id' => $species->id, 'alias' => $alias],
                );
            }
        }
    }
}
