<?php

use Illuminate\Database\Migrations\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a compound index on (event_at, request_path prefix) to threat_logs so the
 * /settings/weird-stats most-attacked-paths query stops doing a full table scan
 * over 2.7M+ rows.
 *
 * Without this: GROUP BY request_path filtered by event_at >= 7d ago hits a
 * full table scan + filesort. ~30 seconds on 2.7M rows.
 *
 * With this: index range scan over the event_at portion + index-ordered group
 * on the request_path prefix. Drops to seconds even on cold cache.
 *
 * Prefix length 64 chars: cheap key size (64 * 4 bytes for utf8mb4 = 256 bytes)
 * and matches the practical attack-path lengths we GROUP on (most are short
 * enough that the prefix is the full string).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Prefix indexes (`request_path(64)`) and SHOW INDEX are MySQL-only
        // syntax, and this index exists purely as a MySQL performance fix.
        // On sqlite (tests) correctness doesn't depend on it — skip.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Skip if it already exists (re-running on partial-state DBs).
        $exists = collect(DB::select('SHOW INDEX FROM threat_logs WHERE Key_name = ?', ['threat_logs_event_at_request_path_index']))->isNotEmpty();
        if ($exists) {
            return;
        }

        // Use raw SQL because Laravel's schema builder doesn't expose prefix length
        // for index columns directly.
        DB::statement('CREATE INDEX threat_logs_event_at_request_path_index ON threat_logs (event_at, request_path(64))');
    }

    public function down(): void
    {
        Schema::table('threat_logs', function (Blueprint $table) {
            $table->dropIndexIfExists('threat_logs_event_at_request_path_index');
        });
    }
};
