<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per nightly run of the standalone backup-relay droplet (Pressable
 * backups -> S3 Glacier Instant Retrieval, replacing ManageWP's 90-day
 * Pressable retention). Written by POST /api/pressable-backup-relay/report —
 * see App\Http\Controllers\PressableBackupRelayController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_relay_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sites_total');
            $table->unsignedInteger('sites_archived');
            $table->unsignedInteger('sites_skipped');
            $table->unsignedInteger('sites_failed');

            // Array of {domain, error} for the sites that failed archiving this run.
            $table->json('failures')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_relay_runs');
    }
};
