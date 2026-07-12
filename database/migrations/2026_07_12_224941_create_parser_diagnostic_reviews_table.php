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
        Schema::create('parser_diagnostic_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('raw_scrape_payload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parser_error_id')->nullable()->constrained()->nullOnDelete();
            $table->char('diagnostic_fingerprint', 64);
            $table->string('status', 32)->default('pending');
            $table->string('provider', 32)->default('openai');
            $table->string('model', 100);
            $table->string('prompt_version', 50);
            $table->string('schema_version', 50);
            $table->string('classification', 50)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('validated_result')->nullable();
            $table->text('rationale')->nullable();
            $table->string('response_id')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->timestamps();

            $table->unique(
                ['raw_scrape_payload_id', 'diagnostic_fingerprint'],
                'parser_diagnostic_reviews_payload_fingerprint_unique',
            );
            $table->index(['status', 'created_at'], 'parser_diagnostic_reviews_status_created_index');
            $table->index(['classification', 'confidence'], 'parser_diagnostic_reviews_classification_confidence_index');
            $table->index(['provider', 'response_id'], 'parser_diagnostic_reviews_provider_response_index');
            $table->index('created_at', 'parser_diagnostic_reviews_retention_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_diagnostic_reviews');
    }
};
