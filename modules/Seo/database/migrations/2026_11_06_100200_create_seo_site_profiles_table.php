<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_site_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->string('gsc_site_url')->nullable();
            $table->string('gsc_permission_level', 64)->nullable();
            $table->string('ga4_property_id', 64)->nullable();
            $table->string('ga4_property_name')->nullable();
            $table->boolean('ga4_custom_primary_channel')->default(false);
            $table->string('brand_regex', 4096)->nullable();
            $table->string('brand_terms', 2048)->nullable();
            $table->json('key_events')->nullable();
            $table->boolean('compare_yoy')->default(false);
            $table->text('report_notes')->nullable();
            $table->string('semrush_domain')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->date('history_starts_on')->nullable();
            $table->timestamp('last_gsc_synced_at')->nullable();
            $table->timestamp('last_ga4_synced_at')->nullable();
            $table->string('last_sync_error', 480)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_site_profiles');
    }
};
