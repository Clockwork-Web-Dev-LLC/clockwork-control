<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pressable generates a fresh Lighthouse report roughly once a month per
 * site (confirmed live: get_site_performance_score_history shows one entry
 * per calendar month, and re-fetching /reports/performance/latest on
 * consecutive days returned the identical report's created_at both times).
 * Polling it daily was silently storing the same stale report as a "new"
 * row every day. This column lets PerformanceScanRecorder detect that and
 * skip the duplicate insert instead of building fake history. Null for
 * PSI/GTmetrix, which have no such batch concept — every call there is a
 * genuinely fresh scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_performance_scans', function (Blueprint $table) {
            $table->timestamp('source_generated_at')->nullable()->after('engine');
        });
    }

    public function down(): void
    {
        Schema::table('site_performance_scans', function (Blueprint $table) {
            $table->dropColumn('source_generated_at');
        });
    }
};
