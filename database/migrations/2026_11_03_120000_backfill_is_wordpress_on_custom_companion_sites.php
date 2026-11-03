<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Standalone enrolment (custom hosting, Companion or Renegade plugin) used
     * to create sites without is_wordpress, so they were left out of every
     * WordPress-only job: core checksums, lockout and firewall pulls, plugin
     * detection. Their Companion snapshot already reports a WordPress core,
     * which is the proof SitesController::store and the Site saving hook now
     * use. Query builder, not Eloquent, so archived rows are repaired too.
     */
    public function up(): void
    {
        DB::table('sites')
            ->where('hosting_provider', 'custom')
            ->where('companion_installed', true)
            ->where('is_wordpress', false)
            ->whereNotNull('companion_snapshot->wp_core')
            ->update(['is_wordpress' => true]);
    }

    public function down(): void
    {
        // One-time data repair; the rows were wrong before, so nothing to undo.
    }
};
