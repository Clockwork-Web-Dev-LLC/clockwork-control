<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_off_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')
                ->constrained('notification_recipients')
                ->cascadeOnDelete();
            // Free-text label so the operator UI can show "Shabbat",
            // "Vacation Aug 5–10", etc. — no semantic meaning.
            $table->string('label', 100);

            // Span-aware window: a single row covers the full duration even
            // when it crosses midnight or a day boundary. (Friday 17:00) →
            // (Saturday 20:00) is one row, not two.
            // dow: 0=Sunday … 6=Saturday (Carbon::dayOfWeek convention).
            $table->unsignedTinyInteger('start_dow');
            $table->time('start_time');
            $table->unsignedTinyInteger('end_dow');
            $table->time('end_time');
            // Default Eastern; recipients in other zones can override.
            $table->string('timezone', 64)->default('America/New_York');

            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_off_windows');
    }
};
