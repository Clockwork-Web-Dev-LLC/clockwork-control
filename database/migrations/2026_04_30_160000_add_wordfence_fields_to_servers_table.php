<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Mirrors auto_ban_llar — independent toggle so the user can trust LLAR
            // (only-failed-logins) but still want manual review for Wordfence's broader rules.
            $table->boolean('auto_ban_wordfence')->default(false)->after('auto_ban_llar');
            $table->timestamp('last_wordfence_pull_at')->nullable()->after('last_llar_pull_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['auto_ban_wordfence', 'last_wordfence_pull_at']);
        });
    }
};
