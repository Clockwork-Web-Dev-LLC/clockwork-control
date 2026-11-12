<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_ga4_key_event_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('month');
            $table->string('event_name', 191);
            $table->decimal('key_events', 14, 2)->default(0);
            $table->unsignedInteger('event_count')->default(0);
            $table->boolean('is_final')->default(false);
            $table->boolean('subject_to_thresholding')->default(false);
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['site_id', 'month', 'event_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ga4_key_event_months');
    }
};
