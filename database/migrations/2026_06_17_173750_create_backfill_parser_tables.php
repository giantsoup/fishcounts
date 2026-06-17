<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backfill_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status')->default('pending')->index();
            $table->json('source_ids');
            $table->unsignedSmallInteger('batch_size_days')->default(7);
            $table->date('current_date')->nullable();
            $table->unsignedInteger('total_days')->default(0);
            $table->unsignedInteger('processed_days')->default(0);
            $table->unsignedInteger('failed_days')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('backfill_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backfill_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scrape_source_id')->constrained()->cascadeOnDelete();
            $table->date('target_date');
            $table->string('status')->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['backfill_run_id', 'scrape_source_id', 'target_date']);
            $table->index(['status', 'target_date']);
        });

        Schema::create('parser_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('raw_scrape_payload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scrape_source_id')->constrained()->cascadeOnDelete();
            $table->date('target_date')->nullable()->index();
            $table->string('error_type')->index();
            $table->string('raw_field')->nullable();
            $table->text('raw_value')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_errors');
        Schema::dropIfExists('backfill_run_items');
        Schema::dropIfExists('backfill_runs');
    }
};
