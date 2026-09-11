<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('backup_relay_last_size_bytes')
                ->nullable()
                ->after('backup_relay_last_archived_at');
            $table->string('backup_relay_last_sha256', 64)
                ->nullable()
                ->after('backup_relay_last_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'backup_relay_last_size_bytes',
                'backup_relay_last_sha256',
            ]);
        });
    }
};
