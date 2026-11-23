<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A keyword map: which page of a site targets which keyword, and
        // the title and description proposed for it. Maps are dated
        // versions. One per site is current; older ones are read-only.
        Schema::create('seo_keyword_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 20)->default('current'); // current, archived
            $table->string('source', 20)->default('manual'); // manual, csv_import, copy
            $table->foreignId('copied_from_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });

        Schema::create('seo_keyword_map_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained('seo_keyword_maps')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('page_name', 191)->nullable();
            $table->text('url')->nullable();
            // A page that does not exist yet.
            $table->boolean('is_new_page')->default(false);
            $table->string('primary_keyword', 191)->nullable();
            // null means no figure, which is not zero.
            $table->unsignedInteger('volume')->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->decimal('cpc', 10, 2)->nullable();
            // Where volume, difficulty and CPC came from: dataforseo, or
            // "imported" for figures typed in or brought from another tool,
            // which are not comparable with DataForSEO's.
            $table->string('metrics_provider', 20)->nullable();
            $table->timestamp('metrics_fetched_at')->nullable();
            // [{keyword, volume}]
            $table->json('secondary_keywords')->nullable();
            $table->string('intent', 30)->nullable();
            $table->text('proposed_title')->nullable();
            $table->text('proposed_meta')->nullable();
            $table->json('schema_types')->nullable();
            $table->string('ship_status', 20)->default('not_started'); // not_started, drafted, live, planned_new
            $table->date('shipped_on')->nullable();
            $table->string('ship_note', 255)->nullable();
            $table->text('notes')->nullable();
            // Hidden from the map without losing it.
            $table->boolean('excluded')->default(false);
            // The importer could not fully read this row.
            $table->boolean('needs_review')->default(false);
            $table->timestamps();

            $table->index(['map_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keyword_map_rows');
        Schema::dropIfExists('seo_keyword_maps');
    }
};
