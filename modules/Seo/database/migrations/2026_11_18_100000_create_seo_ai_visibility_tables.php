<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A question put to AI assistants on a schedule, for one client.
        Schema::create('seo_ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('prompt', 500);
            $table->char('prompt_hash', 40);
            $table->boolean('is_active')->default(true);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            // The last day each engine's check was queued: {engine: Y-m-d}.
            $table->json('last_posted')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'prompt_hash'], 'seo_ai_prompts_unique');
        });

        // One answer from one AI engine to one prompt on one day. No row
        // means it was not checked. A row with mentioned = false means it
        // was checked and the client did not come up.
        Schema::create('seo_ai_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_id')->constrained('seo_ai_prompts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('engine', 30);
            $table->date('checked_on');
            // The brand or domain appears in the answer's words.
            $table->boolean('mentioned')->default(false);
            // The client's site is among the sources the answer links to.
            $table->boolean('cited')->default(false);
            // The engine returned an answer at all.
            $table->boolean('answered')->default(true);
            $table->text('excerpt')->nullable();
            // Domains the answer cites, in order.
            $table->json('sources')->nullable();
            // Named competitors: domain => {mentioned, cited}.
            $table->json('competitors')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['prompt_id', 'engine', 'checked_on'], 'seo_ai_checks_unique');
            $table->index(['site_id', 'engine', 'checked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ai_checks');
        Schema::dropIfExists('seo_ai_prompts');
    }
};
