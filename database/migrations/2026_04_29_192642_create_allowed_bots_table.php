<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowed_bots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ua_pattern');
            $table->string('pattern_type')->default('substring');
            $table->string('source')->default('arcjet');
            $table->text('reference_url')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'ua_pattern']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_bots');
    }
};
