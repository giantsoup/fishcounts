<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_budget_reservations', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt_number')->nullable()->after('parser_execution_id');
            $table->string('client_request_id', 100)->nullable()->unique()->after('reservation_key');
            $table->string('provider_request_id', 100)->nullable()->index()->after('client_request_id');
            $table->string('provider_response_id', 100)->nullable()->after('provider_request_id');
            $table->unsignedSmallInteger('provider_http_status')->nullable()->after('provider_response_id');
            $table->string('provider_status', 32)->nullable()->after('provider_http_status');
            $table->string('provider_incomplete_reason', 100)->nullable()->after('provider_status');
            $table->string('model', 100)->nullable()->after('provider_incomplete_reason');
            $table->string('service_tier', 32)->nullable()->after('model');
            $table->string('failure_stage', 64)->nullable()->after('service_tier');
            $table->string('failure_category', 64)->nullable()->after('failure_stage');
            $table->string('failure_message', 1000)->nullable()->after('failure_category');
            $table->string('provider_error_code', 100)->nullable()->after('failure_message');
            $table->string('provider_error_type', 100)->nullable()->after('provider_error_code');
            $table->unsignedInteger('input_tokens')->default(0)->after('provider_error_type');
            $table->unsignedInteger('cached_input_tokens')->default(0)->after('input_tokens');
            $table->unsignedInteger('cache_write_tokens')->default(0)->after('cached_input_tokens');
            $table->unsignedInteger('output_tokens')->default(0)->after('cache_write_tokens');
            $table->unsignedInteger('reasoning_tokens')->default(0)->after('output_tokens');
            $table->unsignedInteger('total_tokens')->default(0)->after('reasoning_tokens');
            $table->unsignedInteger('latency_ms')->nullable()->after('total_tokens');
            $table->string('cost_basis', 32)->default('none')->after('actual_micros');
            $table->string('cost_calculation_version', 32)->nullable()->after('cost_basis');
            $table->json('pricing_snapshot')->nullable()->after('cost_calculation_version');
            $table->timestamp('response_received_at')->nullable()->after('reserved_at');
        });

        DB::table('ai_budget_reservations')
            ->whereNotNull('parser_execution_id')
            ->where('status', 'settled')
            ->update([
                'cost_basis' => 'unknown',
                'cost_calculation_version' => 'legacy-parser-accounting',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_budget_reservations', function (Blueprint $table) {
            $table->dropUnique('ai_budget_reservations_client_request_id_unique');
            $table->dropIndex('ai_budget_reservations_provider_request_id_index');
            $table->dropColumn([
                'attempt_number',
                'client_request_id',
                'provider_request_id',
                'provider_response_id',
                'provider_http_status',
                'provider_status',
                'provider_incomplete_reason',
                'model',
                'service_tier',
                'failure_stage',
                'failure_category',
                'failure_message',
                'provider_error_code',
                'provider_error_type',
                'input_tokens',
                'cached_input_tokens',
                'cache_write_tokens',
                'output_tokens',
                'reasoning_tokens',
                'total_tokens',
                'latency_ms',
                'cost_basis',
                'cost_calculation_version',
                'pricing_snapshot',
                'response_received_at',
            ]);
        });
    }
};
