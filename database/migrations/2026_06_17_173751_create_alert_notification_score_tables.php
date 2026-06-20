<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('species_id')->constrained()->restrictOnDelete();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedTinyInteger('minimum_score')->default(70);
            $table->unsignedInteger('minimum_total_count')->nullable();
            $table->decimal('minimum_count_per_angler', 8, 2)->nullable();
            $table->unsignedTinyInteger('trend_window_days')->default(3);
            $table->unsignedTinyInteger('baseline_window_days')->default(7);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('discord_enabled')->default(false);
            $table->boolean('include_in_weekly_digest')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_enabled']);
        });

        Schema::create('alert_rule_trip_type', function (Blueprint $table): void {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'trip_type_id']);
        });

        Schema::create('alert_rule_region', function (Blueprint $table): void {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'region_id']);
        });

        Schema::create('alert_rule_landing', function (Blueprint $table): void {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'landing_id']);
        });

        Schema::create('alert_rule_boat', function (Blueprint $table): void {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'boat_id']);
        });

        Schema::create('notification_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->index();
            $table->string('name');
            $table->text('destination');
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'channel', 'name']);
        });

        Schema::create('score_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('run_date')->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('score_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('score_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->date('score_date')->index();
            $table->unsignedTinyInteger('score');
            $table->string('level')->index();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('total_anglers')->nullable();
            $table->decimal('count_per_angler', 8, 2)->nullable();
            $table->unsignedInteger('boat_count')->default(0);
            $table->unsignedInteger('landing_count')->default(0);
            $table->unsignedTinyInteger('trend_score')->default(0);
            $table->unsignedTinyInteger('count_score')->default(0);
            $table->unsignedTinyInteger('count_per_angler_score')->default(0);
            $table->unsignedTinyInteger('breadth_score')->default(0);
            $table->unsignedTinyInteger('source_confidence_score')->default(0);
            $table->jsonb('explanation');
            $table->timestamps();
            $table->unique(['alert_rule_id', 'score_date']);
            $table->index(['level', 'score_date']);
        });

        Schema::create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('score_result_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->date('event_date')->index();
            $table->string('level');
            $table->unsignedTinyInteger('score');
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('discord_sent_at')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['alert_rule_id', 'event_type', 'event_date']);
            $table->index(['user_id', 'event_date']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel')->index();
            $table->string('notification_type')->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('score_results');
        Schema::dropIfExists('score_runs');
        Schema::dropIfExists('notification_destinations');
        Schema::dropIfExists('alert_rule_boat');
        Schema::dropIfExists('alert_rule_landing');
        Schema::dropIfExists('alert_rule_region');
        Schema::dropIfExists('alert_rule_trip_type');
        Schema::dropIfExists('alert_rules');
    }
};
