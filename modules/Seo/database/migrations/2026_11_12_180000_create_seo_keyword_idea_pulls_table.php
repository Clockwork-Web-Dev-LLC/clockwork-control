<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One bought list of keyword ideas. The figures for each keyword
        // live in seo_keyword_metrics; this row remembers which keywords
        // came back for which seeds, so the list can be read again free.
        Schema::create('seo_keyword_idea_pulls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 20); // ideas, suggestions, related
            $table->json('seeds');
            $table->string('market_key', 40);
            $table->json('keywords');
            $table->unsignedInteger('keyword_count')->default(0);
            // How many the provider had in total for these seeds.
            $table->unsignedInteger('available')->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keyword_idea_pulls');
    }
};
