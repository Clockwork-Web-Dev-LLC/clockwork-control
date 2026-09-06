<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captures the operator's multi-domain destination config at form-submit time:
 *
 *   - primary_domain: the canonical domain the destination site serves at
 *     (defaults to source.domain)
 *   - serve_www: bool — whether to also serve www.<primary_domain>
 *   - aliases: array of {domain, mode} where mode is 'serve' (alias serves
 *     same content) or 'redirect' (302 to primary)
 *
 * Stored as a single JSON blob to keep migrations idempotent and avoid the
 * pivot-table overhead for what's typically 1-3 entries per migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_migrations', function (Blueprint $table) {
            $table->json('additional_domains')->nullable()->after('auto_cutover');
        });
    }

    public function down(): void
    {
        Schema::table('site_migrations', function (Blueprint $table) {
            $table->dropColumn('additional_domains');
        });
    }
};
