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
        Schema::table('backfill_runs', function (Blueprint $table): void {
            $table->unsignedInteger('unavailable_days')->default(0)->after('failed_days');
            $table->timestamp('pause_requested_at')->nullable()->after('cancel_requested_at');
        });

        Schema::table('backfill_run_items', function (Blueprint $table): void {
            $table->foreignId('scrape_run_id')->nullable()->after('scrape_source_id')->constrained()->nullOnDelete();
            $table->foreignId('raw_scrape_payload_id')->nullable()->after('scrape_run_id')->constrained()->nullOnDelete();
            $table->index(['backfill_run_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backfill_run_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('raw_scrape_payload_id');
            $table->dropConstrainedForeignId('scrape_run_id');
            $table->dropIndex(['backfill_run_id', 'status']);
        });

        Schema::table('backfill_runs', function (Blueprint $table): void {
            $table->dropColumn(['unavailable_days', 'pause_requested_at']);
        });
    }
};
