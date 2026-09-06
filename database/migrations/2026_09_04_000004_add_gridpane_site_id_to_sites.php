<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sites', 'gridpane_site_id')) {
            Schema::table('sites', function (Blueprint $table) {
                // GridPane addresses each WordPress install by a numeric or string site ID.
                // Like SpinupWP and Cloudways, a GridPane site belongs to a server (server_id),
                // while gridpane_site_id identifies the remote application in GridPane's API.
                $table->string('gridpane_site_id', 64)->nullable()->unique()->after('cloudways_app_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sites', 'gridpane_site_id')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('gridpane_site_id');
            });
        }
    }
};
