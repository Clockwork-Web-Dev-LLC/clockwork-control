<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic key-value store for runtime-configurable settings the user edits via UI.
        // Anything driven by .env stays in config(); this table is for the things we don't
        // want to require a scheduler restart to change.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
