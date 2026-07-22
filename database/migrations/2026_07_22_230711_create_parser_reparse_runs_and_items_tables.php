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
        Schema::create('parser_reparse_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('initial_open_errors')->default(0);
            $table->unsignedInteger('initial_alias_errors')->default(0);
            $table->unsignedInteger('initial_structural_errors')->default(0);
            $table->unsignedInteger('initial_payloads')->default(0);
            $table->unsignedInteger('affected_dates')->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('queued_items')->default(0);
            $table->unsignedInteger('completed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('remaining_open_errors')->nullable();
            $table->unsignedInteger('remaining_alias_errors')->nullable();
            $table->unsignedInteger('remaining_structural_errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('parser_reparse_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parser_reparse_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raw_scrape_payload_id')->constrained()->restrictOnDelete();
            $table->foreignId('scrape_source_id')->constrained()->restrictOnDelete();
            $table->date('target_date');
            $table->string('mode', 32);
            $table->unsignedInteger('sequence');
            $table->string('status', 32)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('date_deduplicated_at')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->unique(['parser_reparse_run_id', 'raw_scrape_payload_id', 'mode'], 'parser_reparse_items_manifest_unique');
            $table->index(['parser_reparse_run_id', 'status']);
            $table->index(['parser_reparse_run_id', 'target_date', 'status'], 'parser_reparse_items_date_status_index');
            $table->index(['parser_reparse_run_id', 'target_date', 'scrape_source_id', 'sequence'], 'parser_reparse_items_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_reparse_items');
        Schema::dropIfExists('parser_reparse_runs');
    }
};
