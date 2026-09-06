<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45);
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->text('reason')->nullable();
            $table->string('llm_verdict')->nullable();
            $table->text('llm_reasoning')->nullable();
            $table->string('decision')->default('approved');
            $table->string('decided_by')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('unbanned_at')->nullable();
            $table->timestamps();

            $table->index(['ip', 'server_id']);
            $table->index('banned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
