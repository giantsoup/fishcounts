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
        Schema::create('parser_diagnostic_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parser_diagnostic_review_id')
                ->nullable()
                ->constrained(indexName: 'parser_review_actions_review_id_foreign')
                ->nullOnDelete();
            $table->foreignId('parser_error_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_email');
            $table->string('action', 32);
            $table->unsignedSmallInteger('review_attempt')->default(0);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(
                ['parser_diagnostic_review_id', 'action', 'review_attempt'],
                'parser_review_actions_review_action_attempt_unique',
            );
            $table->index(['action', 'created_at'], 'parser_review_actions_action_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_diagnostic_review_actions');
    }
};
