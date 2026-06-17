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
            '1-2-day' => ['Half Day', '1/2 Day'],
            '3-4-day' => ['Three Quarter Day', '3/4 Day'],
            'full-day' => ['Full Day'],
        ];

        foreach ($aliases as $slug => $values) {
            $tripType = TripType::query()->where('slug', $slug)->firstOrFail();

            foreach ($values as $alias) {
                TripTypeAlias::query()->updateOrCreate(
                    ['normalized_alias' => Str::of($alias)->lower()->squish()->toString()],
                    ['trip_type_id' => $tripType->id, 'alias' => $alias],
                );
            }
        }
    }
}
