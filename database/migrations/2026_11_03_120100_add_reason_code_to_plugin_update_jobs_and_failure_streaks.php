<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured cause of a failed update, next to the free-text error.
 *
 * Values come from App\Support\UpgraderOutput::REASON_* (fs_permission,
 * download_unauthorized, bad_package, no_package, not_offered,
 * upgrader_error). NULL means the cause is unknown (transport failure,
 * reactivation failure, rows written before this column existed).
 *
 * On the streak it is the cause of the most recent counted failure; the
 * recorder uses it to treat a file-permission failure as a site problem
 * (one site-level pause) rather than a plugin one (one auto-ignore per
 * plugin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_update_jobs', function (Blueprint $table) {
            $table->string('reason_code', 32)->nullable()->after('error');
        });

        Schema::table('plugin_update_failure_streaks', function (Blueprint $table) {
            $table->string('reason_code', 32)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_update_failure_streaks', function (Blueprint $table) {
            $table->dropColumn('reason_code');
        });

        Schema::table('plugin_update_jobs', function (Blueprint $table) {
            $table->dropColumn('reason_code');
        });
    }
};
