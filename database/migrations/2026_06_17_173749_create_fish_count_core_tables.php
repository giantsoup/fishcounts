<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('landings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('website_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['region_id', 'is_active']);
        });

        Schema::create('boats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('species', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('species_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('species_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->unique();
            $table->timestamps();
        });

        Schema::create('trip_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('trip_type_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_type_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->unique();
            $table->timestamps();
        });

        Schema::create('scrape_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('source_type')->index();
            $table->string('base_url');
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('supports_historical_dates')->default(false);
            $table->boolean('supports_landing_filter')->default(false);
            $table->unsignedSmallInteger('rate_limit_seconds')->default(10);
            $table->text('notes')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
        });

        Schema::create('scrape_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scrape_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('run_type')->index();
            $table->date('target_date')->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('raw_scrape_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scrape_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scrape_source_id')->constrained()->cascadeOnDelete();
            $table->date('target_date')->index();
            $table->text('url');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('fetched_at');
            $table->timestamp('parsed_at')->nullable();
            $table->string('parser_version')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['scrape_source_id', 'target_date', 'payload_hash']);
        });

        Schema::create('trip_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained('scrape_sources')->cascadeOnDelete();
            $table->foreignId('raw_scrape_payload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('landing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('boat_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trip_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('trip_date')->index();
            $table->string('source_trip_identifier')->nullable();
            $table->unsignedSmallInteger('anglers')->nullable();
            $table->string('raw_boat_name')->nullable();
            $table->string('raw_landing_name')->nullable();
            $table->string('raw_trip_type')->nullable();
            $table->text('raw_fish_count_text')->nullable();
            $table->boolean('is_deduped_primary')->default(true)->index();
            $table->string('dedupe_key')->index();
            $table->unsignedTinyInteger('source_confidence')->default(100);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'source_trip_identifier']);
            $table->index(['landing_id', 'trip_date']);
            $table->index(['boat_id', 'trip_date']);
            $table->index(['trip_type_id', 'trip_date']);
            $table->index(['region_id', 'trip_date']);
            $table->index(['source_id', 'trip_date']);
        });

        Schema::create('species_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('species_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count');
            $table->unsignedInteger('released_count')->default(0);
            $table->boolean('is_retained_count')->default(true);
            $table->string('raw_species_name')->nullable();
            $table->string('raw_count_text')->nullable();
            $table->timestamps();
            $table->unique(['trip_report_id', 'species_id', 'is_retained_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('species_counts');
        Schema::dropIfExists('trip_reports');
        Schema::dropIfExists('raw_scrape_payloads');
        Schema::dropIfExists('scrape_runs');
        Schema::dropIfExists('scrape_sources');
        Schema::dropIfExists('trip_type_aliases');
        Schema::dropIfExists('trip_types');
        Schema::dropIfExists('species_aliases');
        Schema::dropIfExists('species');
        Schema::dropIfExists('boats');
        Schema::dropIfExists('landings');
        Schema::dropIfExists('regions');
    }
};
