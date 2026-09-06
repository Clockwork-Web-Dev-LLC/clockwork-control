<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror SpinupWP's per-site update-availability booleans so we can show
     * "core/themes/plugins updates pending" without re-hitting the API on every
     * page load. Source: GET /v1/sites/{id} returns wp_core_update,
     * wp_theme_updates, wp_plugin_updates as booleans.
     *
     * Nullable = "haven't pulled this from SpinupWP yet" — distinct from
     * false ("we checked and there's nothing pending").
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('wp_core_update')->nullable()->after('llar_enabled');
            $table->boolean('wp_theme_updates')->nullable()->after('wp_core_update');
            $table->boolean('wp_plugin_updates')->nullable()->after('wp_theme_updates');
            $table->timestamp('wp_updates_checked_at')->nullable()->after('wp_plugin_updates');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'wp_core_update',
                'wp_theme_updates',
                'wp_plugin_updates',
                'wp_updates_checked_at',
            ]);
        });
    }
};
