<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('environmental_sources')
            ->whereIn('slug', [
                'usno_moon',
                'usno_moon_coronado_islands',
                'noaa_coops_la_jolla',
                'noaa_coops_san_diego',
            ])
            ->update(['supports_historical_dates' => true]);
    }

    public function down(): void
    {
        DB::table('environmental_sources')
            ->whereIn('slug', [
                'usno_moon',
                'usno_moon_coronado_islands',
                'noaa_coops_la_jolla',
                'noaa_coops_san_diego',
            ])
            ->update(['supports_historical_dates' => false]);
    }
};
