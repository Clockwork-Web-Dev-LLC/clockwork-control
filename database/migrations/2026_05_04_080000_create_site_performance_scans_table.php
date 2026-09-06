<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily performance/Lighthouse scans for care-plan sites.
 *
 * One row per scan run. Mobile-strategy by default (matches Google's
 * authoritative ranking surface). Schema shape is engine-agnostic — populated
 * from PageSpeed Insights v5 today, but GTmetrix/WebPageTest would slot into
 * the same columns if we ever swap engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_performance_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scanned_at')->index();
            $table->string('status', 16);
            $table->string('strategy', 16)->default('mobile');

            // Lighthouse Performance category (0-100). Null when status='failed'.
            $table->unsignedTinyInteger('performance_score')->nullable();

            // Core Web Vitals + the rest of the Lighthouse Performance metrics.
            // Stored in milliseconds (CLS is unitless: stored ×1000 as integer
            // to keep the column type integer-only and avoid float drift).
            $table->unsignedInteger('lcp_ms')->nullable();   // Largest Contentful Paint
            $table->unsignedInteger('fcp_ms')->nullable();   // First Contentful Paint
            $table->unsignedInteger('tbt_ms')->nullable();   // Total Blocking Time
            $table->unsignedInteger('si_ms')->nullable();    // Speed Index
            $table->unsignedInteger('cls_x1000')->nullable(); // Cumulative Layout Shift × 1000

            $table->unsignedBigInteger('page_weight_bytes')->nullable();
            $table->unsignedSmallInteger('request_count')->nullable();

            $table->string('page_url', 2048)->nullable();
            $table->string('region', 64)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_performance_scans');
    }
};
