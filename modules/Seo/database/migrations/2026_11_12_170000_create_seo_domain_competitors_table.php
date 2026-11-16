<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Domains that rank for the same keywords as a domain, from the
        // latest discovery. One discovery replaces the last.
        Schema::create('seo_domain_competitors', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 191);
            $table->string('market_key', 40);
            $table->string('provider', 40);
            $table->string('competitor_domain', 191);
            // Keywords both rank for.
            $table->unsignedInteger('shared_keywords')->nullable();
            // The competitor's average position on those shared keywords.
            $table->decimal('avg_position', 7, 2)->nullable();
            // Everything the competitor ranks for, and its estimated traffic.
            $table->unsignedInteger('competitor_keywords')->nullable();
            $table->decimal('competitor_traffic', 14, 2)->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['domain', 'market_key', 'provider', 'competitor_domain'], 'seo_domain_competitors_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_domain_competitors');
    }
};
