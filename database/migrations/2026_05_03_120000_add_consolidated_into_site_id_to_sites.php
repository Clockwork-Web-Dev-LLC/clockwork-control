<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the orphan-classification linkback: when our local Site has spinupwp_id=null
 * (because SpinupWP no longer knows about it as a standalone site) but the domain
 * does appear as an additional_domain on another live SpinupWP site, we record
 * the parent's local site_id here.
 *
 * Loose reference (no FK constraint) — matches the convention of
 * `archived_by_migration_id` and avoids cascade-delete surprises if either side
 * gets cleaned up. Always check the parent still exists before following.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('consolidated_into_site_id')->nullable()->after('archived_by_migration_id');
            $table->index('consolidated_into_site_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['consolidated_into_site_id']);
            $table->dropColumn('consolidated_into_site_id');
        });
    }
};
