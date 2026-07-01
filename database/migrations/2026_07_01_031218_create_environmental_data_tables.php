<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('source_type')->index();
            $table->string('location_profile')->index();
            $table->string('station_id')->nullable()->index();
            $table->string('base_url');
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('supports_historical_dates')->default(false);
            $table->unsignedSmallInteger('rate_limit_seconds')->default(10);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
            $table->index(['location_profile', 'is_enabled']);
        });

        Schema::create('environmental_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environmental_source_id')->constrained()->cascadeOnDelete();
            $table->string('location_profile')->index();
            $table->date('observed_date')->index();
            $table->text('url');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('fetched_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique(['environmental_source_id', 'observed_date', 'payload_hash'], 'environmental_payload_source_date_hash_unique');
            $table->index(['location_profile', 'observed_date']);
        });

        Schema::create('environmental_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environmental_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environmental_payload_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_profile')->index();
            $table->date('observed_date')->index();
            $table->timestamp('observed_at')->index();
            $table->string('metric')->index();
            $table->decimal('value', 10, 3)->nullable();
            $table->string('unit')->nullable();
            $table->string('text_value')->nullable();
            $table->jsonb('quality_flags')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['location_profile', 'observed_date', 'metric'], 'environmental_obs_profile_date_metric_index');
            $table->index(['environmental_source_id', 'observed_date', 'metric'], 'environmental_obs_source_date_metric_index');
        });

        Schema::create('environmental_daily_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('location_profile')->index();
            $table->date('observed_date')->index();
            $table->string('moon_phase')->nullable();
            $table->decimal('moon_illumination_percent', 5, 2)->nullable();
            $table->timestamp('moonrise_at')->nullable();
            $table->timestamp('moonset_at')->nullable();
            $table->timestamp('high_tide_at')->nullable();
            $table->decimal('high_tide_height_ft', 8, 3)->nullable();
            $table->timestamp('low_tide_at')->nullable();
            $table->decimal('low_tide_height_ft', 8, 3)->nullable();
            $table->decimal('water_temp_f_avg', 8, 3)->nullable();
            $table->decimal('water_temp_f_min', 8, 3)->nullable();
            $table->decimal('water_temp_f_max', 8, 3)->nullable();
            $table->decimal('wave_height_ft_avg', 8, 3)->nullable();
            $table->decimal('wave_height_ft_min', 8, 3)->nullable();
            $table->decimal('wave_height_ft_max', 8, 3)->nullable();
            $table->decimal('wave_period_seconds_avg', 8, 3)->nullable();
            $table->decimal('wave_period_seconds_min', 8, 3)->nullable();
            $table->decimal('wave_period_seconds_max', 8, 3)->nullable();
            $table->unsignedSmallInteger('wave_direction_degrees_dominant')->nullable();
            $table->decimal('swell_height_ft_avg', 8, 3)->nullable();
            $table->decimal('swell_height_ft_min', 8, 3)->nullable();
            $table->decimal('swell_height_ft_max', 8, 3)->nullable();
            $table->decimal('swell_period_seconds_avg', 8, 3)->nullable();
            $table->decimal('swell_period_seconds_min', 8, 3)->nullable();
            $table->decimal('swell_period_seconds_max', 8, 3)->nullable();
            $table->unsignedSmallInteger('swell_direction_degrees_dominant')->nullable();
            $table->string('condition_summary')->nullable();
            $table->jsonb('coverage')->nullable();
            $table->boolean('is_partial')->default(true)->index();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['location_profile', 'observed_date'], 'environmental_summary_profile_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_daily_summaries');
        Schema::dropIfExists('environmental_observations');
        Schema::dropIfExists('environmental_payloads');
        Schema::dropIfExists('environmental_sources');
    }
};
