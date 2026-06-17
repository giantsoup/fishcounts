<?php

namespace Database\Seeders;

use App\Models\Landing;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LandingSeeder extends Seeder
{
    public function run(): void
    {
        $region = Region::query()->where('slug', 'san-diego')->firstOrFail();

        foreach (["Fisherman's Landing", 'Seaforth Sportfishing', 'H&M Landing', 'Point Loma Sportfishing'] as $name) {
            Landing::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['region_id' => $region->id, 'name' => $name, 'is_active' => true],
            );
        }
    }
}
