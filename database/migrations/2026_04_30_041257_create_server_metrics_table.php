<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at')->index();

            // Stored as percentages 0–100 (or null when DO didn't return data for the metric).
            $table->decimal('cpu_pct', 5, 2)->nullable();
            $table->decimal('memory_pct', 5, 2)->nullable();
            $table->decimal('disk_pct', 5, 2)->nullable();
            $table->decimal('load_1', 6, 2)->nullable();

            $table->timestamps();

            $table->index(['server_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
