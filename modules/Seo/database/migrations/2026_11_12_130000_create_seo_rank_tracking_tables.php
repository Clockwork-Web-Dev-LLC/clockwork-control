<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A keyword whose Google position is checked on a schedule, for one
        // device in one place.
        Schema::create('seo_tracked_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('keyword', 191);
            $table->string('device', 10)->default('desktop');
            $table->unsignedInteger('location_code');
            $table->string('language_code', 8);
            // A city, e.g. "Austin,Texas,United States". null checks the whole country.
            $table->string('location_name', 191)->nullable();
            // Country code or city, so the same keyword can be tracked in
            // two places without a nullable column in the unique index.
            $table->string('location_key', 191);
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            // The last day a check was put on DataForSEO's queue.
            $table->date('last_posted_on')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'keyword', 'device', 'location_key'], 'seo_tracked_keywords_unique');
            $table->index(['is_active', 'last_posted_on']);
        });

        // One check of one tracked keyword on one day. No row means it was
        // not checked. A row with a null position means it was checked and
        // the site was not within the depth looked at. Neither is ever 0.
        Schema::create('seo_rank_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_keyword_id')->constrained('seo_tracked_keywords')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('checked_on');
            // Position among the organic results (what rank trackers report).
            $table->unsignedSmallInteger('position')->nullable();
            // Position among everything on the page, ads and features included.
            $table->unsignedSmallInteger('absolute_position')->nullable();
            $table->text('url')->nullable();
            // How many organic results were looked at.
            $table->unsignedSmallInteger('depth');
            // More than one of the site's pages ranked for this keyword.
            $table->unsignedTinyInteger('pages_ranking')->default(0);
            $table->boolean('has_ai_overview')->default(false);
            $table->boolean('cited_in_ai_overview')->default(false);
            $table->json('features')->nullable();
            // The first ten organic domains, in order.
            $table->json('top_domains')->nullable();
            // Named competitors: domain => position, null when not within depth.
            $table->json('competitors')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tracked_keyword_id', 'checked_on'], 'seo_rank_checks_unique');
            $table->index(['site_id', 'checked_on']);
        });

        Schema::table('seo_site_profiles', function (Blueprint $table) {
            // Competitor domains whose positions are kept with every check.
            $table->json('competitors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seo_site_profiles', function (Blueprint $table) {
            $table->dropColumn('competitors');
        });
        Schema::dropIfExists('seo_rank_checks');
        Schema::dropIfExists('seo_tracked_keywords');
    }
};
