<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_performance_scans', function (Blueprint $table) {
            // Which scanning engine produced this row. Values:
            //   'gtmetrix'     — GTmetrix paid API, primary engine (2026-06-27+)
            //   'psi-fallback' — Google PageSpeed Insights, used only when
            //                    the GTmetrix call errored on a given site/run
            //   'psi'          — legacy rows from before the GTmetrix swap
            //
            // 'psi' default lets existing rows be classified correctly without
            // a backfill — they were all PSI before this migration ran.
            $table->string('engine', 32)->default('psi')->after('strategy');
        });
    }

    public function down(): void
    {
        Schema::table('site_performance_scans', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
