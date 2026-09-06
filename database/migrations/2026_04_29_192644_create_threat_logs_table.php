<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threat_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->timestamp('event_at')->index();
            $table->string('ip', 45)->index();
            $table->string('user_agent', 1024)->nullable();
            $table->string('request_path', 2048)->nullable();
            $table->string('request_method', 16)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'event_at']);
            $table->index(['ip', 'event_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threat_logs');
    }
};
