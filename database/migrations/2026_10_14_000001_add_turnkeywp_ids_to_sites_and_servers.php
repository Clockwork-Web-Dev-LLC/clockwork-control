<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sites', 'turnkeywp_site_id')) {
            Schema::table('sites', function (Blueprint $table) {
                // TurnkeyWP's own site id. Separate from spinupwp_id because a
                // site being migrated is listed by both hosts for a while.
                $table->string('turnkeywp_site_id', 64)->nullable()->unique()->after('gridpane_site_id');
            });
        }

        if (! Schema::hasColumn('servers', 'turnkeywp_server_id')) {
            Schema::table('servers', function (Blueprint $table) {
                // A server TurnkeyWP adopted from SpinupWP carries both ids.
                $table->string('turnkeywp_server_id', 64)->nullable()->unique()->after('spinupwp_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sites', 'turnkeywp_site_id')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropUnique(['turnkeywp_site_id']);
                $table->dropColumn('turnkeywp_site_id');
            });
        }

        if (Schema::hasColumn('servers', 'turnkeywp_server_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropUnique(['turnkeywp_server_id']);
                $table->dropColumn('turnkeywp_server_id');
            });
        }
    }
};
