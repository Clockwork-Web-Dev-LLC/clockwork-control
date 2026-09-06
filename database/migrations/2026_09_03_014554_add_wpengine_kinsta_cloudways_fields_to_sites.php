<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // WP Engine addresses everything by "install" name (a slug, not
            // a numeric id) — no server concept, same shape as pressable_site_id.
            $table->string('wpengine_install_name', 64)->nullable()->unique()->after('pressable_site_id');

            // Kinsta addresses a WordPress install as an "environment" under
            // a site — no server concept either, id is Kinsta's own UUID-ish string.
            $table->string('kinsta_environment_id', 64)->nullable()->unique()->after('wpengine_install_name');

            // Cloudways provisions real servers hosting multiple "apps" each
            // — server_id (already nullable/generic) carries the Server
            // linkage the same way it does for SpinupWP; this column
            // identifies which app on that server this Site row is.
            $table->string('cloudways_app_id', 64)->nullable()->unique()->after('kinsta_environment_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['wpengine_install_name', 'kinsta_environment_id', 'cloudways_app_id']);
        });
    }
};
