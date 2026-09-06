<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site "ignore uptime alerts" relief valve. Distinct from
 * `uptime_monitoring_enabled` (which stops the probe entirely):
 * with an `uptime_ignored_at` set, we KEEP probing — we just don't
 * route the site into Issues / nav badge / Mattermost. Used when a
 * site is known to be down indefinitely (e.g. client offline, domain
 * expired, project paused) and the operator doesn't want the noise
 * but does want a record of when it eventually recovers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('uptime_ignored_at')->nullable()->after('uptime_down_since');
            $table->text('uptime_ignore_reason')->nullable()->after('uptime_ignored_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['uptime_ignored_at', 'uptime_ignore_reason']);
        });
    }
};
