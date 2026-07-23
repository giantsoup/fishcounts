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
        Schema::create('parser_engine_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('previous_engine', 32);
            $table->string('new_engine', 32);
            $table->string('reason', 1000);
            $table->timestamps();

            $table->index(['scrape_source_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_engine_changes');
    }
};
