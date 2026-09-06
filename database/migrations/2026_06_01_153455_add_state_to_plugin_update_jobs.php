<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_update_jobs', function (Blueprint $table) {
            // Subset of companion_snapshot captured just before the update runs:
            // { captured_at, snapshot_age_minutes, active_plugins: [...slugs], stylesheet, template }
            // Null when the site has no cached snapshot at job start time.
            $table->json('state_before')->nullable()->after('messages');

            // Repairs made by the post-update verify call, if Companion >= 1.21.3.
            // Array of { type: 'plugin_reactivated'|'theme_restored', slug, detail }.
            // Null = verify not called (old Companion, or update failed before verify ran).
            // Empty array = verify ran, nothing needed repair.
            $table->json('repairs')->nullable()->after('state_before');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_update_jobs', function (Blueprint $table) {
            $table->dropColumn(['state_before', 'repairs']);
        });
    }
};
