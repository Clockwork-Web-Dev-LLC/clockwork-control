<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // server_id assumes every site lives on a Clockwork-managed Server
        // (SSH creds, cloud-provider identity). Pressable has no server
        // concept at all — every API operation is addressed by site_id
        // alone — so Pressable-hosted sites carry no Server row.
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['server_id']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->change();
        });

        Schema::table('sites', function (Blueprint $table) {
            // cascadeOnDelete preserved: deleting a Server still deletes the
            // SpinupWP sites that reference it. Pressable sites (server_id
            // always null) are simply never subject to that cascade.
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->string('hosting_provider', 32)->default('spinupwp')->after('server_id');
            $table->string('pressable_site_id', 32)->nullable()->unique()->after('spinupwp_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['hosting_provider', 'pressable_site_id']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['server_id']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable(false)->change();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }
};
