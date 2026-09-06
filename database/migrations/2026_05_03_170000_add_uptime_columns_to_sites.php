<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site current state for HTTP uptime monitoring.
 *
 * State machine: 'unknown' → first probe → 'up' or 'down'.
 * Subsequent probes flip 'up'/'down' based on consecutive_failures threshold
 * (>=2 to transition up→down; 1 success to transition down→up).
 *
 * Transition history lives in site_uptime_events (separate migration). These
 * columns are the "current state" snapshot needed for every page render
 * without joining the events table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('uptime_monitoring_enabled')->default(true)->after('care_plan_override');
            $table->string('uptime_state', 16)->default('unknown')->after('uptime_monitoring_enabled');
            $table->timestamp('uptime_last_checked_at')->nullable()->after('uptime_state');
            $table->timestamp('uptime_last_up_at')->nullable()->after('uptime_last_checked_at');
            $table->smallInteger('uptime_last_status_code')->nullable()->after('uptime_last_up_at');
            $table->smallInteger('uptime_consecutive_failures')->unsigned()->default(0)->after('uptime_last_status_code');
            $table->timestamp('uptime_down_since')->nullable()->after('uptime_consecutive_failures');

            // Drives the Issues page "currently down" query.
            $table->index('uptime_state');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['uptime_state']);
            $table->dropColumn([
                'uptime_monitoring_enabled',
                'uptime_state',
                'uptime_last_checked_at',
                'uptime_last_up_at',
                'uptime_last_status_code',
                'uptime_consecutive_failures',
                'uptime_down_since',
            ]);
        });
    }
};
