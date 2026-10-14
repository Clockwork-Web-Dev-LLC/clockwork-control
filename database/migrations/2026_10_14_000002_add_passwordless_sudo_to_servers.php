<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('servers', 'passwordless_sudo')) {
            Schema::table('servers', function (Blueprint $table) {
                // The SSH user can sudo without a password (TurnkeyWP's
                // `turnkey` user, or root), so no ssh_password is stored.
                $table->boolean('passwordless_sudo')->default(false)->after('ssh_password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servers', 'passwordless_sudo')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('passwordless_sudo');
            });
        }
    }
};
