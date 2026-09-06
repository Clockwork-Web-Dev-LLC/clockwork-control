<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('seo_indexable')->default(true)->after('care_plan_enabled');
            $table->string('seo_blocked_reason')->nullable()->after('seo_indexable');
            $table->timestamp('seo_checked_at')->nullable()->after('seo_blocked_reason');
            $table->text('seo_blocked_snippet')->nullable()->after('seo_checked_at');
            $table->boolean('seo_monitoring_enabled')->default(true)->after('seo_blocked_snippet');
            $table->timestamp('seo_state_changed_at')->nullable()->after('seo_monitoring_enabled');

            // Per-vector last-known snapshot (meta tag / X-Robots-Tag header /
            // robots.txt), independent of seo_blocked_reason/seo_blocked_snippet
            // above (which only ever hold the single current top-priority
            // reason). Needed because the three vectors are checked by
            // different code paths at different cadences — without each
            // vector's own persisted state, fixing the higher-priority one
            // silently "forgets" a lower-priority one is still blocking.
            $table->text('seo_meta_snippet')->nullable()->after('seo_state_changed_at');
            $table->text('seo_header_snippet')->nullable()->after('seo_meta_snippet');
            $table->text('seo_robots_snippet')->nullable()->after('seo_header_snippet');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'seo_indexable',
                'seo_blocked_reason',
                'seo_checked_at',
                'seo_blocked_snippet',
                'seo_monitoring_enabled',
                'seo_state_changed_at',
                'seo_meta_snippet',
                'seo_header_snippet',
                'seo_robots_snippet',
            ]);
        });
    }
};
