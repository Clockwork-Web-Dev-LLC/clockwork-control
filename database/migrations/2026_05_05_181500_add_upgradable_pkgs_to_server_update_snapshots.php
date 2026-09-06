<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `upgradable_pkgs` to server_update_snapshots — the per-package list
 * pulled from `apt list --upgradable`. Without this, the snapshot strip
 * could only show counts ("20 packages, 0 security") but not WHICH packages,
 * which is the most useful piece of information for "should I run updates
 * now?". Parsed shape: list of objects { name, from, to, source, security }
 * where `security` is true when the source repo name contains "-security".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_update_snapshots', function (Blueprint $table) {
            $table->json('upgradable_pkgs')->nullable()->after('reboot_required_pkgs');
        });
    }

    public function down(): void
    {
        Schema::table('server_update_snapshots', function (Blueprint $table) {
            $table->dropColumn('upgradable_pkgs');
        });
    }
};
