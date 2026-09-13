<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair migration. The three per-vector snippet columns were appended to
 * 2026_09_06_130000_add_seo_indexability_fields_to_sites_table AFTER that
 * migration had already run on live installs, so any database migrated
 * before the edit has only the first six seo_* columns. On those installs
 * every IndexabilityChecker save() throws "Unknown column 'seo_meta_snippet'"
 * — which killed the entire SEO watchdog (the uptime-probe piggyback
 * swallows the exception per site) from the moment the checker shipped.
 *
 * hasColumn guards make this a no-op on databases that ran the edited
 * nine-column version (fresh installs, test databases), so both variants
 * converge on the same schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'seo_meta_snippet')) {
                $table->text('seo_meta_snippet')->nullable()->after('seo_state_changed_at');
            }
            if (! Schema::hasColumn('sites', 'seo_header_snippet')) {
                $table->text('seo_header_snippet')->nullable()->after('seo_meta_snippet');
            }
            if (! Schema::hasColumn('sites', 'seo_robots_snippet')) {
                $table->text('seo_robots_snippet')->nullable()->after('seo_header_snippet');
            }
        });
    }

    public function down(): void
    {
        // Intentionally a no-op: the columns rightfully belong to the
        // 2026_09_06_130000 migration, whose down() already drops them.
        // Dropping here as well would break rollback ordering on installs
        // where that earlier migration created them.
    }
};
