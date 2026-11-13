<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record who minted each token, and the last four characters of its plaintext so the
     * admin UI can show a masked hint without ever keeping the token itself.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('tokenable_id')->constrained('users')->nullOnDelete();
            $table->string('token_hint', 4)->nullable()->after('token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('token_hint');
        });
    }
};
