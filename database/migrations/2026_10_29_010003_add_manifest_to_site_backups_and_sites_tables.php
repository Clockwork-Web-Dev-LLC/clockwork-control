<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('backup_relay_last_manifest')
                ->nullable()
                ->after('backup_relay_last_sha256');
        });

        Schema::table('site_backups', function (Blueprint $table) {
            $table->json('manifest')
                ->nullable()
                ->after('is_manual');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('backup_relay_last_manifest');
        });

        Schema::table('site_backups', function (Blueprint $table) {
            $table->dropColumn('manifest');
        });
    }
};
