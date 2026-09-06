<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // When true, IPs found in any site's LLAR active-lockouts list on this server are
            // banned via fail2ban automatically. Default false — manual review queue stance.
            $table->boolean('auto_ban_llar')->default(false)->after('clockwork_jail_provisioned_at');

            // Last successful LLAR pull, for the detail page + telemetry.
            $table->timestamp('last_llar_pull_at')->nullable()->after('auto_ban_llar');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['auto_ban_llar', 'last_llar_pull_at']);
        });
    }
};
