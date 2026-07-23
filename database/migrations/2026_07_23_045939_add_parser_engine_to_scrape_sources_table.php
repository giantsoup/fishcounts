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
        Schema::table('scrape_sources', function (Blueprint $table) {
            $table->string('parser_engine', 32)->default('deterministic')->after('source_type')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrape_sources', function (Blueprint $table) {
            $table->dropColumn('parser_engine');
        });
    }
};
