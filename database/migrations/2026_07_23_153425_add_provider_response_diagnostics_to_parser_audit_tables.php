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
        Schema::table('parser_executions', function (Blueprint $table) {
            $table->char('provider_response_body_hash', 64)->nullable()->after('provider_error_type');
            $table->text('provider_output_excerpt')->nullable()->after('provider_response_body_hash');
        });

        Schema::table('ai_budget_reservations', function (Blueprint $table) {
            $table->char('provider_response_body_hash', 64)->nullable()->after('provider_error_type');
            $table->text('provider_output_excerpt')->nullable()->after('provider_response_body_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_executions', function (Blueprint $table) {
            $table->dropColumn([
                'provider_response_body_hash',
                'provider_output_excerpt',
            ]);
        });

        Schema::table('ai_budget_reservations', function (Blueprint $table) {
            $table->dropColumn([
                'provider_response_body_hash',
                'provider_output_excerpt',
            ]);
        });
    }
};
