<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        Region::query()->updateOrCreate(['slug' => 'san-diego'], ['name' => 'San Diego', 'is_active' => true]);
    }
}
