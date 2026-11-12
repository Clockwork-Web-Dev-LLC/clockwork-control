<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One crawl of one site. DataForSEO keeps a crawl for 30 days and
        // has no history between crawls, so everything needed for a trend
        // is copied here when the crawl finishes.
        Schema::create('seo_site_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('task_id', 64)->nullable();
            $table->string('status', 20); // crawling, finished, failed
            $table->unsignedInteger('max_pages');
            $table->boolean('javascript')->default(false);
            $table->unsignedInteger('pages_crawled')->nullable();
            // The provider's 0 to 100 score. Its own formula: not
            // comparable with another tool's "site health".
            $table->decimal('score', 5, 2)->nullable();
            // Site-level totals as given: broken links, duplicate titles...
            $table->json('totals')->nullable();
            // How many pages failed each check.
            $table->json('check_counts')->nullable();
            // Site-wide findings: no sitemap, no robots.txt, no HTTPS redirect...
            $table->json('site_checks')->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->string('error', 500)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->index(['site_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        Schema::create('seo_site_audit_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('seo_site_audits')->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->text('h1')->nullable();
            $table->text('canonical')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedSmallInteger('click_depth')->nullable();
            $table->unsignedInteger('internal_links')->nullable();
            $table->unsignedInteger('external_links')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedInteger('load_ms')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            // The names of the checks this page failed.
            $table->json('failed_checks')->nullable();

            $table->unique(['audit_id', 'url_hash'], 'seo_site_audit_pages_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_site_audit_pages');
        Schema::dropIfExists('seo_site_audits');
    }
};
