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
            $table->char('report_fingerprint', 64)->nullable()->after('context');
            $table->char('diagnostic_fingerprint', 64)->nullable()->after('report_fingerprint');
            $table->index('report_fingerprint', 'parser_errors_report_fingerprint_index');
            $table->unique('diagnostic_fingerprint', 'parser_errors_diagnostic_fingerprint_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_errors', function (Blueprint $table): void {
            $table->dropUnique('parser_errors_diagnostic_fingerprint_unique');
            $table->dropIndex('parser_errors_report_fingerprint_index');
            $table->dropColumn(['report_fingerprint', 'diagnostic_fingerprint']);
        });
    }
};
