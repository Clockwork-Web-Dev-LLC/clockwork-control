<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Distinct from `archived_at`: an archived site is hidden from
            // every listing entirely. An inactive site stays fully visible
            // (Sites list, server page, search) but is excluded from Issues
            // page / nav badge counting and from every site-scoped
            // Mattermost/Slack alert — same "still visible, not monitored"
            // shape as Server.is_ignored, generalized across every issue
            // category instead of one narrow toggle like uptime_ignored_at.
            // Use case: a site is being decommissioned/migrated away but the
            // client asked to keep it reachable a while longer — nobody
            // needs an SSL-renewal or plugin-update ping for it in the
            // meantime.
            $table->boolean('is_inactive')->default(false)->after('archived_at');
            $table->string('inactive_reason')->nullable()->after('is_inactive');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['is_inactive', 'inactive_reason']);
        });
    }
};
