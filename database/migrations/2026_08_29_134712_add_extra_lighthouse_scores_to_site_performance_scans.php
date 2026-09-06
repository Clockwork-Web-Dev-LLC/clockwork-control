<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every performance-scan engine we use (PSI, GTmetrix, and Pressable's own
 * Lighthouse report) returns Accessibility/Best Practices/SEO scores
 * alongside the Performance score — we've been fetching and discarding
 * them. Nullable throughout: historical rows and any engine that genuinely
 * lacks one of these categories just show "—" rather than 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_performance_scans', function (Blueprint $table) {
            $table->unsignedTinyInteger('accessibility_score')->nullable()->after('performance_score');
            $table->unsignedTinyInteger('best_practices_score')->nullable()->after('accessibility_score');
            $table->unsignedTinyInteger('seo_score')->nullable()->after('best_practices_score');
        });
    }

    public function down(): void
    {
        Schema::table('site_performance_scans', function (Blueprint $table) {
            $table->dropColumn(['accessibility_score', 'best_practices_score', 'seo_score']);
        });
    }
};
