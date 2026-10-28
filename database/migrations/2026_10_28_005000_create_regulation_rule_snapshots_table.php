<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_rule_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->char('snapshot_hash', 64)->unique();
            $table->string('jurisdiction', 32);
            $table->string('taxon', 100);
            $table->date('effective_from');
            $table->date('effective_until');
            $table->json('rule');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('retrieved_at');
            $table->timestamps();
            $table->index(['jurisdiction', 'taxon', 'effective_from', 'effective_until'], 'regulation_snapshot_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_rule_snapshots');
    }
};
