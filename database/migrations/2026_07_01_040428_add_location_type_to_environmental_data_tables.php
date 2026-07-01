<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environmental_sources', function (Blueprint $table): void {
            $table->string('location_type')->default('local')->after('location_profile')->index('environmental_sources_location_type_index');
            $table->index(['location_type', 'is_enabled'], 'environmental_sources_type_enabled_index');
        });

        foreach ([
            'environmental_payloads',
            'environmental_observations',
            'environmental_daily_summaries',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->string('location_type')->default('local')->after('location_profile')->index("{$tableName}_location_type_index");
                $table->index(['location_type', 'observed_date'], "{$tableName}_type_date_index");
            });
        }

        DB::table('environmental_sources')->update(['location_type' => 'local']);
        DB::table('environmental_payloads')->update(['location_type' => 'local']);
        DB::table('environmental_observations')->update(['location_type' => 'local']);
        DB::table('environmental_daily_summaries')->update(['location_type' => 'local']);
    }

    public function down(): void
    {
        Schema::table('environmental_sources', function (Blueprint $table): void {
            $table->dropIndex('environmental_sources_type_enabled_index');
            $table->dropIndex('environmental_sources_location_type_index');
            $table->dropColumn('location_type');
        });

        foreach ([
            'environmental_payloads',
            'environmental_observations',
            'environmental_daily_summaries',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex("{$tableName}_type_date_index");
                $table->dropIndex("{$tableName}_location_type_index");
                $table->dropColumn('location_type');
            });
        }
    }
};
