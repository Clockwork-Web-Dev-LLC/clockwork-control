<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Domains that link to a competitor but not to the client, from
        // the latest pull for that pair. One pull replaces the last.
        Schema::create('seo_backlink_gap', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 191);
            $table->string('competitor_domain', 191);
            $table->string('provider', 40);
            $table->string('referring_domain', 191);
            $table->unsignedSmallInteger('rank')->nullable();
            // Links from it to the competitor.
            $table->unsignedInteger('backlinks')->nullable();
            $table->unsignedTinyInteger('spam_score')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['domain', 'competitor_domain', 'provider', 'referring_domain'], 'seo_backlink_gap_unique');
            $table->index(['domain', 'referring_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_backlink_gap');
    }
};
