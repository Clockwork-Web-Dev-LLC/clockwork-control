<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the failure streak could not tell before.
 *
 * last_retry_queued_at: when the nightly loop last queued a retry of an
 * auto-paused slug (a new release or a security fix). The retried version
 * is written to last_target_version at the same time, so a retry that ends
 * without a counted failure (timed out, reaped, transport error) still
 * counts as that version's one attempt, and a same-version security retry
 * waits out its back-off from here.
 *
 * consecutive_fs_failures / fs_streak_started_job_id: how many counted
 * failures in a row were file-permission failures, and the job that began
 * that run. The site-wide pause needs N of those in a row, and checks for
 * a successful update on the site since that job, rather than reading the
 * latest failure's reason and counting back over every failed job row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_update_failure_streaks', function (Blueprint $table) {
            $table->timestamp('last_retry_queued_at')->nullable()->after('last_failed_at');
            $table->unsignedInteger('consecutive_fs_failures')->default(0)->after('reason_code');
            $table->unsignedBigInteger('fs_streak_started_job_id')->nullable()->after('consecutive_fs_failures');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_update_failure_streaks', function (Blueprint $table) {
            $table->dropColumn(['last_retry_queued_at', 'consecutive_fs_failures', 'fs_streak_started_job_id']);
        });
    }
};
