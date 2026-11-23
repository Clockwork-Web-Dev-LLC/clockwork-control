<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A domain's backlink totals for one month. Kept per domain, so a
        // competitor two clients share is bought once.
        Schema::create('seo_backlink_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 191);
            $table->date('month');
            $table->string('provider', 40);
            // null means the provider gave no figure, which is not zero.
            $table->unsignedBigInteger('backlinks')->nullable();
            $table->unsignedInteger('referring_domains')->nullable();
            $table->unsignedInteger('referring_main_domains')->nullable();
            $table->unsignedInteger('referring_domains_nofollow')->nullable();
            $table->unsignedInteger('broken_backlinks')->nullable();
            // The provider's own 0 to 100 measures. Not another tool's
            // authority or toxicity score.
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedTinyInteger('spam_score')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['domain', 'month', 'provider'], 'seo_backlink_snapshots_unique');
        });

        // The domains that link to a domain. A new pull adds what is new
        // and marks what has gone, so new and lost are known without
        // buying them separately.
        Schema::create('seo_backlink_referring_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 191);
            $table->string('provider', 40);
            $table->string('referring_domain', 191);
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedInteger('backlinks')->nullable();
            $table->unsignedTinyInteger('spam_score')->nullable();
            // When the provider first saw a link from it.
            $table->date('first_seen')->nullable();
            // When Control first and last saw it in a pull.
            $table->timestamp('found_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            // Set when a later, complete pull no longer has it.
            $table->timestamp('lost_at')->nullable();

            $table->unique(['domain', 'provider', 'referring_domain'], 'seo_backlink_referring_domains_unique');
            $table->index(['domain', 'lost_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_backlink_referring_domains');
        Schema::dropIfExists('seo_backlink_snapshots');
    }
};
