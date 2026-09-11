<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'uptime_require_keyword')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // INSTANT add at end of table. Callers should clear leftover MDL holders first
            // (this table is hit continuously by uptime + the dashboard).
            DB::statement('SET SESSION lock_wait_timeout = 15');
            DB::statement('ALTER TABLE sites ADD COLUMN uptime_require_keyword VARCHAR(120) NULL, ALGORITHM=INSTANT');

            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->string('uptime_require_keyword', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('uptime_require_keyword');
        });
    }
};
