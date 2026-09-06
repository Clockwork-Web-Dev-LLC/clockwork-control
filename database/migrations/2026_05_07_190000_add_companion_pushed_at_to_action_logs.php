<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror-push tracking on action_logs.
 *
 * ActionLogger pushes every newly-created row to Companion's
 * /action-log/append endpoint, but the push isn't tracked. When a site's
 * Companion is reinstalled (e.g. after a server migration) the local
 * mirror table is wiped — and we have no way to know which rows from our
 * authoritative copy already made it back across, so we can't safely
 * re-push without creating duplicates.
 *
 * companion_pushed_at = timestamp of successful append. NULL means either
 * the push failed or never happened. The new backfill command walks NULL
 * rows for a site and pushes them, setting this column on success — so
 * subsequent runs are idempotent.
 *
 * Backfill plan: existing rows are left NULL. The first time anyone
 * needs the mirror restored on a site, run the backfill command;
 * day-to-day operation is unaffected because new pushes set the timestamp
 * automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->timestamp('companion_pushed_at')->nullable()->after('elapsed_ms');
            $table->index(['site_id', 'companion_pushed_at'], 'action_logs_site_pushed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->dropIndex('action_logs_site_pushed_idx');
            $table->dropColumn('companion_pushed_at');
        });
    }
};
