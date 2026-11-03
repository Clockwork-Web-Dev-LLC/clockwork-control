<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks a run of uptime probes the site rate-limited (HTTP 429):
 *
 *   - uptime_probe_blocked_since: when the current unbroken run of blocked
 *     probes started. Null while probes get a real answer.
 *   - uptime_probe_blocked_alerted_at: when ops was told about this run, so
 *     the "probe blocked for N hours" chat notice fires once per run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'uptime_probe_blocked_since',
            'uptime_probe_blocked_alerted_at',
        ];

        $missing = array_values(array_filter($columns, fn (string $c) => ! Schema::hasColumn('sites', $c)));
        if ($missing === []) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // INSTANT add at end of table (sites is hit continuously by the
            // uptime probe and the dashboard).
            DB::statement('SET SESSION lock_wait_timeout = 15');
            $adds = implode(', ', array_map(fn (string $c) => "ADD COLUMN {$c} TIMESTAMP NULL", $missing));
            DB::statement("ALTER TABLE sites {$adds}, ALGORITHM=INSTANT");

            return;
        }

        Schema::table('sites', function (Blueprint $table) use ($missing) {
            foreach ($missing as $column) {
                $table->timestamp($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['uptime_probe_blocked_since', 'uptime_probe_blocked_alerted_at']);
        });
    }
};
