<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('bucket_at');
            // BIGINT UNSIGNED equivalents via unsignedBigInteger — these can
            // accumulate large values over a busy hour (CPU microseconds for
            // 1000s of requests). Default 0 so the UPSERT doesn't need to
            // know the column shape.
            $table->unsignedBigInteger('cpu_us_total')->default(0);
            $table->unsignedBigInteger('wall_us_total')->default(0);
            $table->unsignedBigInteger('mem_peak_bytes')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'bucket_at']);
            $table->index('bucket_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_metrics');
    }
};
