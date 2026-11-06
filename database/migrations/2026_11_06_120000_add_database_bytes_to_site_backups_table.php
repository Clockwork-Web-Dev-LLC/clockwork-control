<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database half of a combined (SpinupWP) backup, so client reports
     * can compare it with the database's size: the combined size hides a
     * broken dump behind the files. Filled for rows still in the bucket by
     * the next clockwork:record-site-backups run.
     */
    public function up(): void
    {
        Schema::table('site_backups', function (Blueprint $table) {
            $table->unsignedBigInteger('database_bytes')->nullable()->after('bytes');
        });
    }

    public function down(): void
    {
        Schema::table('site_backups', function (Blueprint $table) {
            $table->dropColumn('database_bytes');
        });
    }
};
