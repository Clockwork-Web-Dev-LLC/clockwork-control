<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('clockwork_jail_provisioned_at')->nullable()->after('last_alert_at');
            $table->text('last_provision_log')->nullable()->after('clockwork_jail_provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['clockwork_jail_provisioned_at', 'last_provision_log']);
        });
    }
};
