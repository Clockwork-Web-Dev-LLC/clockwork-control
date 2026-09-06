<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_queue', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->index();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('llm_verdict');
            $table->text('llm_reasoning');
            $table->decimal('llm_score', 5, 4)->nullable();
            $table->json('evidence')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_queue');
    }
};
