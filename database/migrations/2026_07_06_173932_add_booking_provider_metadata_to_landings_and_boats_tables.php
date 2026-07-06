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
        Schema::table('landings', function (Blueprint $table): void {
            $table->string('booking_provider')->nullable()->after('website_url');
            $table->string('booking_base_url')->nullable()->after('booking_provider');

            $table->index('booking_provider');
        });

        Schema::table('boats', function (Blueprint $table): void {
            $table->string('booking_provider_identifier')->nullable()->after('booking_url');

            $table->index('booking_provider_identifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boats', function (Blueprint $table): void {
            $table->dropIndex(['booking_provider_identifier']);
            $table->dropColumn('booking_provider_identifier');
        });

        Schema::table('landings', function (Blueprint $table): void {
            $table->dropIndex(['booking_provider']);
            $table->dropColumn(['booking_provider', 'booking_base_url']);
        });
    }
};
