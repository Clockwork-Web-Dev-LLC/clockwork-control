<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Set by clockwork:detect-stuck-companion-state when a site
            // transitions into a stuck state (failed install never retried,
            // or an installed Companion gone silent past the snapshot-staleness
            // threshold). Cleared back to null on recovery. The pair exists
            // purely to make that transition detectable — same
            // "state + timestamp, alert only on change" shape as
            // ContactFormTest's failure_streak / state_changed_at columns.
            $table->timestamp('companion_stuck_since')->nullable()->after('companion_snapshot_at');
            $table->string('companion_stuck_reason', 32)->nullable()->after('companion_stuck_since');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['companion_stuck_since', 'companion_stuck_reason']);
        });
    }
};
