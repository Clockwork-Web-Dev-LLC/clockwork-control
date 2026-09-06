<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-site Companion snapshot cache.
 *
 * `companion_snapshot` holds the full /snapshot payload from the mu-plugin
 * (plugins, admins, wp_cron, comments_summary). Refreshed every ~15 min by
 * `clockwork:refresh-companion-snapshot`. Issues page + per-site detail tabs
 * read from this column rather than calling Companion live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('companion_snapshot')->nullable()->after('companion_last_seen_at');
            $table->timestamp('companion_snapshot_at')->nullable()->after('companion_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['companion_snapshot', 'companion_snapshot_at']);
        });
    }
};
