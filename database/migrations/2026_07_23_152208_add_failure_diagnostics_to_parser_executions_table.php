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
        Schema::table('parser_executions', function (Blueprint $table) {
            $table->string('fallback_stage', 64)->nullable()->after('fallback_category');
            $table->string('fallback_message', 1000)->nullable()->after('fallback_stage');
            $table->string('failure_stage', 64)->nullable()->after('failure_category');
            $table->unsignedSmallInteger('provider_http_status')->nullable()->after('provider_response_id');
            $table->string('provider_request_id', 100)->nullable()->after('provider_http_status');
            $table->string('provider_status', 32)->nullable()->after('provider_request_id');
            $table->string('provider_incomplete_reason', 100)->nullable()->after('provider_status');
            $table->string('provider_error_code', 100)->nullable()->after('provider_incomplete_reason');
            $table->string('provider_error_type', 100)->nullable()->after('provider_error_code');
            $table->boolean('cost_is_estimated')->default(false)->after('cost_micros');
        });

        DB::table('parser_executions')
            ->where('cost_micros', '>', 0)
            ->where('total_tokens', 0)
            ->update(['cost_is_estimated' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_executions', function (Blueprint $table) {
            $table->dropColumn([
                'fallback_stage',
                'fallback_message',
                'failure_stage',
                'provider_http_status',
                'provider_request_id',
                'provider_status',
                'provider_incomplete_reason',
                'provider_error_code',
                'provider_error_type',
                'cost_is_estimated',
            ]);
        });
    }
};
