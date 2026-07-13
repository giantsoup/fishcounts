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
        Schema::create('historical_ai_review_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->string('status', 16)->default('pending');
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('max_items');
            $table->unsignedBigInteger('budget_micros');
            $table->unsignedBigInteger('estimated_item_cost_micros');
            $table->string('authorization_reference', 255)->unique();
            $table->char('selection_fingerprint', 64)->unique();
            $table->unsignedInteger('selected_count')->default(0);
            $table->unsignedInteger('dispatched_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedBigInteger('estimated_spent_micros')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['scope', 'date_from', 'date_to']);
        });

        Schema::create('historical_ai_review_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_ai_review_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raw_scrape_payload_id')->nullable()->constrained()->nullOnDelete();
            $table->char('payload_hash', 64);
            $table->char('item_fingerprint', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->timestamps();

            $table->unique(
                ['historical_ai_review_run_id', 'item_fingerprint'],
                'historical_ai_review_items_run_fingerprint_unique',
            );
            $table->index(['historical_ai_review_run_id', 'status', 'id'], 'historical_ai_review_items_run_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historical_ai_review_run_items');
        Schema::dropIfExists('historical_ai_review_runs');
    }
};
