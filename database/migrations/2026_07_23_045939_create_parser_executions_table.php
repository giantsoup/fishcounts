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
        Schema::create('parser_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_scrape_payload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scrape_source_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('requested_engine', 32);
            $table->string('selected_engine', 32)->nullable();
            $table->string('status', 32)->default('running');
            $table->char('payload_hash', 64);
            $table->char('sanitized_input_hash', 64)->nullable();
            $table->string('parser_version', 100)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('service_tier', 32)->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->string('schema_version', 32)->nullable();
            $table->string('sanitizer_version', 32)->nullable();
            $table->string('catalog_version', 100)->nullable();
            $table->json('deterministic_snapshot')->nullable();
            $table->json('ai_snapshot')->nullable();
            $table->json('comparison')->nullable();
            $table->string('comparison_status', 32)->nullable();
            $table->string('fallback_category', 64)->nullable();
            $table->string('failure_category', 64)->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->string('provider_response_id', 100)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('downstream_dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['scrape_source_id', 'status', 'created_at'], 'parser_executions_source_status_created_index');
            $table->index(['raw_scrape_payload_id', 'created_at'], 'parser_executions_payload_created_index');
            $table->index(['requested_engine', 'selected_engine', 'created_at'], 'parser_executions_engines_created_index');
            $table->index(['created_at', 'status'], 'parser_executions_created_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_executions');
    }
};
