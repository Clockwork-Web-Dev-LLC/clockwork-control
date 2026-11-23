<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many keywords a domain ranks for in one country, once a
        // month. Kept per domain, not per site, so a competitor that two
        // clients share is bought once.
        Schema::create('seo_domain_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 191);
            $table->string('market_key', 40);
            $table->date('month');
            $table->string('provider', 40);
            // null means the provider gave no figure, which is not zero.
            $table->unsignedInteger('keywords')->nullable();
            $table->unsignedInteger('top3')->nullable();
            $table->unsignedInteger('top10')->nullable();
            $table->unsignedInteger('top20')->nullable();
            $table->unsignedInteger('top100')->nullable();
            // Counts per position bucket exactly as the provider gave them.
            $table->json('buckets')->nullable();
            $table->decimal('estimated_traffic', 14, 2)->nullable();
            $table->decimal('traffic_value', 14, 2)->nullable();
            $table->unsignedInteger('new_keywords')->nullable();
            $table->unsignedInteger('up_keywords')->nullable();
            $table->unsignedInteger('down_keywords')->nullable();
            $table->unsignedInteger('lost_keywords')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['domain', 'market_key', 'month', 'provider'], 'seo_domain_snapshots_unique');
        });

        // The keywords a domain ranks for, as of the latest pull. One pull
        // replaces the last: this is a current list, not a history.
        Schema::create('seo_ranked_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 191);
            $table->string('market_key', 40);
            $table->string('provider', 40);
            $table->string('keyword', 191);
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('previous_position')->nullable();
            $table->text('url')->nullable();
            $table->unsignedInteger('volume')->nullable();
            $table->decimal('cpc', 10, 2)->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->string('intent', 20)->nullable();
            $table->decimal('estimated_traffic', 12, 2)->nullable();
            // When the provider last looked at this keyword's results page.
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['domain', 'market_key', 'provider', 'keyword'], 'seo_ranked_keywords_unique');
            $table->index(['domain', 'market_key', 'position']);
            $table->index(['market_key', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ranked_keywords');
        Schema::dropIfExists('seo_domain_snapshots');
    }
};
