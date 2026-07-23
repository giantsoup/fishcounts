<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('parser_diagnostic_reviews', function (Blueprint $table): void {
            $table->string('service_tier', 32)->nullable()->after('model');
            $table->unsignedInteger('cache_write_tokens')->default(0)->after('cached_input_tokens');
            $table->string('cost_calculation_version', 32)->nullable()->after('estimated_cost_micros');
            $table->json('pricing_snapshot')->nullable()->after('cost_calculation_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_diagnostic_reviews', function (Blueprint $table): void {
            $table->dropColumn([
                'service_tier',
                'cache_write_tokens',
                'cost_calculation_version',
                'pricing_snapshot',
            ]);
        });
    }
};
