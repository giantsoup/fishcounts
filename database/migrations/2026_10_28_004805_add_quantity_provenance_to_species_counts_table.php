<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species_counts', function (Blueprint $table): void {
            $table->unsignedInteger('count')->nullable()->change();
            $table->string('quantity_basis', 32)->default('legacy_unclassified');
            $table->json('components')->nullable();
            $table->boolean('has_estimated_components')->default(false);
            $table->boolean('has_unquantified_components')->default(false);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE species_counts ADD CONSTRAINT species_counts_quantity_state CHECK ((`count` IS NOT NULL OR has_unquantified_components = 1) AND (quantity_basis <> 'limits_unquantified' OR (`count` IS NULL AND has_unquantified_components = 1)) AND (quantity_basis NOT IN ('explicit', 'corroborated', 'limits_estimated') OR `count` IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('species_counts')->whereNull('count')->exists()) {
            throw new RuntimeException('Reevaluate qualitative catches before removing quantity provenance.');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE species_counts DROP CHECK species_counts_quantity_state');
        }
        Schema::table('species_counts', function (Blueprint $table): void {
            $table->dropColumn(['quantity_basis', 'components', 'has_estimated_components', 'has_unquantified_components']);
            $table->unsignedInteger('count')->nullable(false)->change();
        });
    }
};
