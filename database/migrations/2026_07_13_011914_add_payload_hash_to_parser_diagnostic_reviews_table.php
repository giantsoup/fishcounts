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
            $table->char('payload_hash', 64)->nullable()->after('raw_scrape_payload_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_diagnostic_reviews', function (Blueprint $table): void {
            $table->dropColumn('payload_hash');
        });
    }
};
