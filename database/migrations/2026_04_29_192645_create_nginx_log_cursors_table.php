<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nginx_log_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('log_path');
            $table->unsignedBigInteger('inode')->nullable();
            $table->unsignedBigInteger('offset')->default(0);
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'log_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nginx_log_cursors');
    }
};
