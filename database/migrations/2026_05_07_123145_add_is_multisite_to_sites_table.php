<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Whether this site is a WordPress multisite (network) install.
            // Populated from Companion /health on RefreshCompanionCapabilities.
            // Drives default HTTP timeout — multisite sites take 30s+ just to
            // boot the network on every REST request, so 30s default is too
            // tight for short calls (SSO, snapshot, action-log push).
            //
            // Default false: safe for old Companion (<1.16.4) which doesn't
            // report this field — they keep the standard 30s timeout. Once
            // they upgrade and refresh capabilities, the flag flips and the
            // longer timeout kicks in automatically.
            $table->boolean('is_multisite')->default(false)->after('is_wordpress');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('is_multisite');
        });
    }
};
