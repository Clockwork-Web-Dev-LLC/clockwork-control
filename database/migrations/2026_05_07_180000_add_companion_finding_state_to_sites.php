<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site, per-finding alert state for the Companion malware scanner.
 *
 * Today the wp_config_recently_modified probe re-raises every scan inside
 * the 30-day recency window, even when the file hasn't been touched since
 * the last alert. This column lets the scanner remember the mtime it last
 * alerted on so it can suppress repeats and only re-fire when wp-config.php
 * is genuinely modified again.
 *
 * Shape (JSON, keyed by finding kind):
 *   {
 *     "wp_config_recently_modified": {
 *       "alerted_mtime_unix": 1746626160,
 *       "alerted_at": "2026-05-07T18:00:00+00:00"
 *     },
 *     // future kinds slot in alongside without a schema change
 *   }
 *
 * Null = never alerted on any kind. New sites start null; backfill
 * unnecessary because the next scan that DOES raise the finding writes
 * the state on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('companion_finding_state')->nullable()->after('companion_snapshot_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('companion_finding_state');
        });
    }
};
