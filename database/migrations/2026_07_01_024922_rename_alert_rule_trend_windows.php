<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->renameColumn('trend_window_days', 'recent_window_days');
            $table->renameColumn('baseline_window_days', 'comparison_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->renameColumn('recent_window_days', 'trend_window_days');
            $table->renameColumn('comparison_window_days', 'baseline_window_days');
        });
    }
};
