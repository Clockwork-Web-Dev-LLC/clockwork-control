<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Mirrored from SpinupWP's per-server API response (refreshed by the daily import).
            $table->string('ubuntu_version', 32)->nullable()->after('ssh_user');
            $table->boolean('upgrade_required')->default(false)->after('ubuntu_version');
            $table->boolean('reboot_required')->default(false)->after('upgrade_required');

            // Action lifecycle for the apt-update button. Status values:
            //   null/queued/running/completed/failed
            $table->string('update_status', 16)->nullable()->after('reboot_required');
            $table->timestamp('update_queued_at')->nullable()->after('update_status');
            $table->timestamp('update_started_at')->nullable()->after('update_queued_at');
            $table->timestamp('update_completed_at')->nullable()->after('update_started_at');
            $table->longText('last_update_log')->nullable()->after('update_completed_at');
            $table->timestamp('scheduled_reboot_at')->nullable()->after('last_update_log');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'ubuntu_version',
                'upgrade_required',
                'reboot_required',
                'update_status',
                'update_queued_at',
                'update_started_at',
                'update_completed_at',
                'last_update_log',
                'scheduled_reboot_at',
            ]);
        });
    }
};
