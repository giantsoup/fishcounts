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
        Schema::create('backfill_reparse_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backfill_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_payloads')->default(0);
            $table->unsignedInteger('queued_payloads')->default(0);
            $table->unsignedInteger('completed_payloads')->default(0);
            $table->unsignedInteger('failed_payloads')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['backfill_run_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backfill_reparse_runs');
    }
};
