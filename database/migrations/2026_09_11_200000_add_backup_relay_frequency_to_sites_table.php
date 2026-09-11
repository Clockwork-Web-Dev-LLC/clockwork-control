<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('backup_relay_frequency', 20)
                ->nullable()
                ->after('backup_relay_enabled');
        });

        DB::table('sites')
            ->where('hosting_provider', 'custom')
            ->whereNull('backup_relay_frequency')
            ->update(['backup_relay_frequency' => 'daily']);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('backup_relay_frequency');
        });
    }
};
