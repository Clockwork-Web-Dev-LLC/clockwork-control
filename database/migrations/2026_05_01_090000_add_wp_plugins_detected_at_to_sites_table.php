<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timestamp of the last successful 'wp plugin' detection probe over SSH.
     * Lets the UI distinguish "we checked and the plugin isn't there" (the
     * field is false AND this column is set) from "we've never asked"
     * (the field is false AND this column is null).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('wp_plugins_detected_at')->nullable()->after('llar_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('wp_plugins_detected_at');
        });
    }
};
