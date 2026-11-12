<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_gsc_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('date');
            $table->string('segment', 16); // all, nonbranded
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('position', 7, 2)->default(0);
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['site_id', 'date', 'segment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_gsc_daily');
    }
};
