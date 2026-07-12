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
        Schema::create('ai_budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('limit_micros');
            $table->unsignedBigInteger('reserved_micros')->default(0);
            $table->unsignedBigInteger('spent_micros')->default(0);
            $table->timestamps();

            $table->unique(
                ['provider', 'period_type', 'period_start'],
                'ai_budget_periods_provider_type_start_unique',
            );
            $table->index('period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_budget_periods');
    }
};
