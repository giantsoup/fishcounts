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
        Schema::table('ai_budget_reservations', function (Blueprint $table): void {
            $table->foreignId('parser_execution_id')
                ->nullable()
                ->after('parser_diagnostic_review_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_budget_reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parser_execution_id');
        });
    }
};
