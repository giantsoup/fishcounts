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
        Schema::create('parser_bug_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parser_diagnostic_review_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('review_attempt')->default(0);
            $table->char('signature', 64)->unique();
            $table->string('source_slug');
            $table->string('status', 32)->default('preview');
            $table->boolean('requires_approval')->default(true);
            $table->string('title');
            $table->longText('body');
            $table->json('labels');
            $table->unsignedBigInteger('issue_number')->nullable();
            $table->string('issue_url')->nullable();
            $table->string('issue_state', 16)->nullable();
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->string('approved_by_email')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'parser_bug_reports_status_created_index');
            $table->index(['issue_state', 'last_seen_at'], 'parser_bug_reports_issue_state_seen_index');
        });

        Schema::create('parser_bug_report_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parser_bug_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parser_diagnostic_review_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parser_error_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('review_attempt');
            $table->timestamp('seen_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['parser_diagnostic_review_id', 'review_attempt'],
                'parser_bug_occurrences_review_attempt_unique',
            );
            $table->index(['parser_bug_report_id', 'invalidated_at'], 'parser_bug_occurrences_report_valid_index');
        });

        Schema::table('parser_diagnostic_reviews', function (Blueprint $table): void {
            $table->foreignId('parser_bug_report_id')
                ->nullable()
                ->after('parser_error_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_diagnostic_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parser_bug_report_id');
        });

        Schema::dropIfExists('parser_bug_report_occurrences');
        Schema::dropIfExists('parser_bug_reports');
    }
};
