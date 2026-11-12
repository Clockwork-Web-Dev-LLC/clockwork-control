<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a page on a client's site says about itself right now: the
        // latest audit per URL. A newer audit replaces the row.
        Schema::create('seo_page_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);
            // ok, blocked (a firewall or login stopped us), not_html, error.
            // Only "ok" rows describe the page; the rest say why they can't.
            $table->string('fetch_status', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('final_url')->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('h1s')->nullable();
            $table->json('h2s')->nullable();
            $table->text('canonical')->nullable();
            $table->string('meta_robots', 191)->nullable();
            $table->json('schema_types')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('audited_at')->nullable();

            $table->unique(['site_id', 'url_hash'], 'seo_page_audits_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_page_audits');
    }
};
