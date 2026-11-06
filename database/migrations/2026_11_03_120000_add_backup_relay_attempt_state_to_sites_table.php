<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site backup relay attempt state, so an operator (and later the client
 * report) can see why a site has no fresh off-site archive without reading
 * the application log. Written on every failed archive attempt and cleared
 * on the next success.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('backup_relay_last_attempt_at')
                ->nullable()
                ->after('backup_relay_last_manifest');
            $table->text('backup_relay_last_error')
                ->nullable()
                ->after('backup_relay_last_attempt_at');
            $table->timestamp('backup_relay_failing_since')
                ->nullable()
                ->after('backup_relay_last_error');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'backup_relay_last_attempt_at',
                'backup_relay_last_error',
                'backup_relay_failing_since',
            ]);
        });
    }
};
