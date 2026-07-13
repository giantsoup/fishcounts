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
            $table->string('failure_category', 32)->nullable()->after('failure_message');
            $table->index('failure_category', 'parser_diagnostic_reviews_failure_category_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_diagnostic_reviews', function (Blueprint $table): void {
            $table->dropIndex('parser_diagnostic_reviews_failure_category_index');
            $table->dropColumn('failure_category');
        });
    }
};
