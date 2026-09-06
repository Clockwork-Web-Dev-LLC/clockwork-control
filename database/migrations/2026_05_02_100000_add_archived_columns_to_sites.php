<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a Site as archived after a migration's cutover completes. Archived sites
 * are excluded from polling, dashboards, and ingest — they're a recovery
 * snapshot living on the old server until the +7-day cleanup window elapses.
 *
 * archived_by_migration_id is a back-reference so the cleanup workflow can
 * find the migration that archived this site without an extra join from
 * site_migrations → sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('cloudflare_checked_at');
            $table->foreignId('archived_by_migration_id')
                ->nullable()
                ->after('archived_at')
                ->constrained('site_migrations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by_migration_id');
            $table->dropColumn('archived_at');
        });
    }
};
