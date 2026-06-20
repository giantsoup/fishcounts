<?php

namespace Database\Seeders;

use App\Models\TripType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TripTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['1/2 Day AM', '1/2 Day PM', '1/2 Day Twilight', '1/2 Day', '3/4 Day', 'Full Day', 'Full Day Coronado Islands', 'Overnight', '1.5 Day', '2 Day', '2.5 Day', '3 Day', '3.5 Day', 'Multi-day', 'Long Range', 'Unknown'];

        foreach ($types as $index => $name) {
            TripType::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $index + 1, 'is_active' => true],
            );
        }
    }
}
