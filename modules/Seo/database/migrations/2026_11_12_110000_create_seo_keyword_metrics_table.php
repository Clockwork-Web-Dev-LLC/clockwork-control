<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bought keyword metrics, kept so the same keyword is not bought
        // twice within a month. Shared by every site in the same market.
        Schema::create('seo_keyword_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 191);
            // Country and language, e.g. "2840-en" (United States, English).
            $table->string('market_key', 40);
            $table->string('provider', 40);
            // null means the provider had no figure, which is not zero.
            $table->unsignedInteger('volume')->nullable();
            $table->json('monthly')->nullable();
            $table->decimal('cpc', 10, 2)->nullable();
            $table->decimal('paid_competition', 6, 4)->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->string('intent', 20)->nullable();
            $table->json('secondary_intents')->nullable();
            // City-level volume, bought separately, keyed by the city name.
            $table->json('local_volumes')->nullable();
            // The provider knew nothing about this keyword. Kept so it is
            // not bought again every time someone asks.
            $table->boolean('no_data')->default(false);
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['keyword', 'market_key', 'provider'], 'seo_keyword_metrics_unique');
        });

        Schema::table('seo_site_profiles', function (Blueprint $table) {
            // Where the client's customers search. Defaults: United States
            // (DataForSEO location 2840), English.
            $table->unsignedInteger('market_location_code')->nullable()->after('semrush_domain');
            $table->string('market_language_code', 8)->nullable()->after('market_location_code');
            // For local clients: "Phoenix,Arizona,United States".
            $table->string('market_city', 191)->nullable()->after('market_language_code');
        });
    }

    public function down(): void
    {
        Schema::table('seo_site_profiles', function (Blueprint $table) {
            $table->dropColumn(['market_location_code', 'market_language_code', 'market_city']);
        });
        Schema::dropIfExists('seo_keyword_metrics');
    }
};
