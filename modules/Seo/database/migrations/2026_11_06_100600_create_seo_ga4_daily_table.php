<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // For the daily chart and the pre-2026-05-13 AI estimate only. GA4
        // month figures never come from summing these rows.
        Schema::create('seo_ga4_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension', 16); // channel, ai_source
            $table->string('value', 191);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['site_id', 'date', 'dimension', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ga4_daily');
    }
};
