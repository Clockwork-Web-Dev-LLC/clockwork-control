<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Per-site override for the GTmetrix test location. NULL means
            // "use the global default" (clockwork.gtmetrix.region in config).
            //
            // Valid values are GTmetrix location slugs returned by their
            // /locations endpoint. As of 2026-06-27: 'vancouver', 'london',
            // 'sydney', 'dallas', 'mumbai', 'sao-paulo', 'hong-kong', plus
            // additional regions added by GTmetrix over time. We don't
            // enum-constrain here because GTmetrix adds new regions and
            // we'd rather a bad value fail-soft (the API rejects unknown
            // locations with a clean 400) than block a legitimate update.
            $table->string('performance_scan_region', 64)->nullable()->after('psi_unavailable_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('performance_scan_region');
        });
    }
};
