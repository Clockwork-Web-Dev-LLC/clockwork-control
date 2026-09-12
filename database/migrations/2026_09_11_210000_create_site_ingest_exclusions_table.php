<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_ingest_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('hosting_provider');
            $table->string('provider_site_id')->nullable();
            $table->string('domain');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('excluded_by')->nullable();
            $table->timestamps();

            $table->unique(['hosting_provider', 'domain']);
            $table->index(['hosting_provider', 'provider_site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_ingest_exclusions');
    }
};
