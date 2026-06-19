<?php

namespace Database\Seeders;

use App\Models\TripType;
use App\Models\TripTypeAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TripTypeAliasSeeder extends Seeder
{
    public function run(): void
    {
        $aliases = [
            '1/2 Day' => ['Half Day', '1/2 Day'],
            '1/2 Day Twilight' => ['Twilight'],
            '3/4 Day' => ['Three Quarter Day', '3/4 Day'],
            'Full Day' => ['Full Day'],
        ];

        foreach ($aliases as $name => $values) {
            $tripType = TripType::query()->where('name', $name)->firstOrFail();

            foreach ($values as $alias) {
                TripTypeAlias::query()->updateOrCreate(
                    ['normalized_alias' => Str::of($alias)->lower()->squish()->toString()],
                    ['trip_type_id' => $tripType->id, 'alias' => $alias],
                );
            }
        }
    }
}
