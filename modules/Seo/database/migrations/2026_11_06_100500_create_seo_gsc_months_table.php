<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_gsc_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('month');
            $table->boolean('is_final')->default(false);
            $table->unsignedInteger('rows_page')->default(0);
            $table->unsignedInteger('rows_query')->default(0);
            $table->unsignedInteger('rows_page_query')->default(0);
            $table->boolean('possibly_truncated')->default(false);
            $table->timestamp('synced_at')->nullable();

            $table->unique(['site_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_gsc_months');
    }
};
