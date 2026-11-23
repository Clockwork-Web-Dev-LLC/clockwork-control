<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_gsc_month_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('month'); // first of the month
            $table->string('dimension', 16); // page, query, page_query
            $table->string('page', 2048)->nullable();
            $table->string('query', 512)->nullable();
            $table->char('row_hash', 40);
            $table->boolean('is_branded')->nullable()->index();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('position', 7, 2)->default(0);
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['site_id', 'month', 'row_hash']);
            $table->index(['site_id', 'month', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_gsc_month_rows');
    }
};
