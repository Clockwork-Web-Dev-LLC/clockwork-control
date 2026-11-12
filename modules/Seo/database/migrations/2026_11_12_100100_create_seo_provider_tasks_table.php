<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work handed to a provider's queue and collected later. DataForSEO's
        // cheapest prices are for tasks posted now and fetched when ready.
        Schema::create('seo_provider_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('feature', 40);
            // What kind of result this is, so the right collector picks it
            // up (e.g. serp_organic, on_page_crawl).
            $table->string('kind', 60);
            $table->string('task_id', 64);
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            // Whatever the collector needs to file the result: the tracked
            // keyword id, the audit run id, ...
            $table->json('context')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('error', 480)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('collected_at')->nullable();

            $table->unique(['provider', 'task_id'], 'seo_provider_tasks_unique');
            $table->index(['kind', 'status', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_provider_tasks');
    }
};
