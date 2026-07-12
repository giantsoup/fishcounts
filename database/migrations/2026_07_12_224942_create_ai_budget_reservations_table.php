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
        Schema::create('ai_budget_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_budget_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parser_diagnostic_review_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reservation_key', 100)->unique();
            $table->string('status', 16)->default('reserved');
            $table->unsignedBigInteger('reserved_micros');
            $table->unsignedBigInteger('actual_micros')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_budget_reservations');
    }
};
