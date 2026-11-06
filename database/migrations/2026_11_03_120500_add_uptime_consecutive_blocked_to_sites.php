<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * uptime_consecutive_blocked: how many probes in a row the site answered
 * with HTTP 429. A site only enters probe_blocked after as many 429s in a
 * row as it takes failures to go down, so one rate-limit blip leaves no
 * event, no action log and no month-end hold. Kept apart from
 * uptime_consecutive_failures, which a 429 never touches. Any other answer
 * resets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'uptime_consecutive_blocked')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // INSTANT add at end of table (sites is hit continuously by the
            // uptime probe and the dashboard).
            DB::statement('SET SESSION lock_wait_timeout = 15');
            DB::statement('ALTER TABLE sites ADD COLUMN uptime_consecutive_blocked SMALLINT UNSIGNED NOT NULL DEFAULT 0, ALGORITHM=INSTANT');

            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('uptime_consecutive_blocked')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('uptime_consecutive_blocked');
        });
    }
};
