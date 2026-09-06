<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('unique_ips')->default(0);
            $table->unsignedBigInteger('bytes_sent')->default(0);

            $table->unsignedBigInteger('status_2xx')->default(0);
            $table->unsignedBigInteger('status_3xx')->default(0);
            $table->unsignedBigInteger('status_4xx')->default(0);
            $table->unsignedBigInteger('status_5xx')->default(0);

            $table->json('top_paths')->nullable();
            $table->json('top_ips')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_traffic_daily');
    }
};
