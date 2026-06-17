<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RegionSeeder::class,
            LandingSeeder::class,
            SpeciesSeeder::class,
            SpeciesAliasSeeder::class,
            TripTypeSeeder::class,
            TripTypeAliasSeeder::class,
            ScrapeSourceSeeder::class,
            AdminUserSeeder::class,
            DefaultAlertRuleSeeder::class,
        ]);
    }
}
