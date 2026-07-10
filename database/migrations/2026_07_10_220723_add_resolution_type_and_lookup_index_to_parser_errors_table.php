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
        Schema::table('parser_errors', function (Blueprint $table): void {
            $table->string('resolution_type', 20)->nullable()->after('resolved_by_user_id');
            $table->index(['resolved_at', 'created_at'], 'parser_errors_resolution_created_index');
            $table->index('created_at', 'parser_errors_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_errors', function (Blueprint $table): void {
            $table->dropIndex('parser_errors_resolution_created_index');
            $table->dropIndex('parser_errors_created_at_index');
            $table->dropColumn('resolution_type');
        });
    }
};
