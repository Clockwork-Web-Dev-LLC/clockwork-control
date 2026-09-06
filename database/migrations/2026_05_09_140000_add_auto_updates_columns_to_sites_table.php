<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Care-plan sites get nightly auto-updates by default. Operators
            // (or customer requests) can pause individual sites without
            // dropping them off the care plan entirely — useful during
            // redesigns, post-incident, staging migrations, etc.
            $table->boolean('auto_updates_paused')->default(false)->after('care_plan_override');
            $table->string('auto_updates_paused_reason', 255)->nullable()->after('auto_updates_paused');

            // Last time the nightly loop touched this site (regardless of
            // whether any updates were actually pending). Drives the dedup
            // guard so a re-run during the same night doesn't double-process,
            // and surfaces in the UI as an at-a-glance freshness indicator.
            $table->timestamp('auto_updates_last_run_at')->nullable()->after('auto_updates_paused_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'auto_updates_paused',
                'auto_updates_paused_reason',
                'auto_updates_last_run_at',
            ]);
        });
    }
};
