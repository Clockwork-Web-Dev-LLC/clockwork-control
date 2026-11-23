<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The profile no longer holds a domain for another vendor's tool: nothing
 * read it. Keyword data comes from DataForSEO, keyed by the site's own domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('seo_site_profiles', 'semrush_domain')) {
            Schema::table('seo_site_profiles', function (Blueprint $table) {
                $table->dropColumn('semrush_domain');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('seo_site_profiles', 'semrush_domain')) {
            Schema::table('seo_site_profiles', function (Blueprint $table) {
                $table->string('semrush_domain')->nullable();
            });
        }
    }
};
