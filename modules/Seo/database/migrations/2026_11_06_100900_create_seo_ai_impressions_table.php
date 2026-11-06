<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The GSC Generative AI report has no API: one typed (or Claude-read)
        // number per site per month, or "not available", never a guessed 0.
        Schema::create('seo_ai_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('month');
            $table->string('status', 16); // entered, not_available
            $table->unsignedInteger('ai_impressions')->nullable();
            $table->json('top_pages')->nullable();
            $table->string('source', 16); // mcp, manual (export in 1b)
            $table->string('note', 200)->nullable();
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ai_impressions');
    }
};
