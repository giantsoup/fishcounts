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
        Schema::create('parser_report_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('raw_scrape_payload_id')->constrained()->restrictOnDelete();
            $table->foreignId('parser_diagnostic_review_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('review_attempt');
            $table->foreignId('parser_bug_report_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('report_index');
            $table->char('report_fingerprint', 64);
            $table->char('paragraph_fingerprint', 64);
            $table->string('parser_version', 100);
            $table->string('correction_schema_version', 50);
            $table->string('status', 32)->default('pending');
            $table->json('corrections');
            $table->json('original_parse');
            $table->json('corrected_parse');
            $table->json('affected_scope');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->string('created_by_email');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->string('approved_by_email')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('first_applied_at')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->foreignId('disabled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disabled_by_name')->nullable();
            $table->string('disabled_by_email')->nullable();
            $table->text('disable_reason')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['raw_scrape_payload_id', 'parser_diagnostic_review_id', 'report_fingerprint', 'parser_version', 'paragraph_fingerprint', 'correction_schema_version', 'review_attempt'],
                'parser_report_overrides_identity_unique',
            );
            $table->index(['raw_scrape_payload_id', 'status', 'report_index'], 'parser_report_overrides_application_index');
            $table->index(['parser_bug_report_id', 'status'], 'parser_report_overrides_issue_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_report_overrides');
    }
};
