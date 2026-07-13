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
        Schema::create('parser_diagnostic_review_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('raw_scrape_payload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('preparing');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->timestamps();

            $table->index(
                ['raw_scrape_payload_id', 'status', 'created_at'],
                'parser_diagnostic_review_runs_payload_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_diagnostic_review_runs');
    }
};
