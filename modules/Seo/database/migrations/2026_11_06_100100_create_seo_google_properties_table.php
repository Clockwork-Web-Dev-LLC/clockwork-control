<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_google_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('seo_google_connections')->cascadeOnDelete();
            $table->string('kind', 8); // gsc, ga4
            $table->string('property'); // GSC siteUrl, or properties/123
            $table->string('display_name')->nullable();
            $table->string('permission_level', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'kind', 'property']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_google_properties');
    }
};
