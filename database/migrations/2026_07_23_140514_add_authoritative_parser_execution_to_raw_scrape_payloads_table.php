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
        Schema::table('raw_scrape_payloads', function (Blueprint $table): void {
            $table->foreignId('authoritative_parser_execution_id')
                ->nullable()
                ->after('parser_version')
                ->constrained('parser_executions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('raw_scrape_payloads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('authoritative_parser_execution_id');
        });
    }
};
