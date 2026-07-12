<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('species')
            ->whereIn('slug', [
                'bluefin-tuna',
                'dorado',
                'mako-shark',
                'opah',
                'wahoo',
                'yellowfin-tuna',
                'yellowtail',
            ])
            ->update(['environmental_location_profile' => 'coronado_islands']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('species')
            ->whereIn('slug', [
                'bluefin-tuna',
                'dorado',
                'mako-shark',
                'opah',
                'wahoo',
                'yellowfin-tuna',
                'yellowtail',
            ])
            ->update(['environmental_location_profile' => 'san_diego_bight']);
    }
};
