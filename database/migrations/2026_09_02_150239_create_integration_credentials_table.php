<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dedicated table for API credentials, separate from app_settings
        // (which is plain unencrypted JSON — fine for toggles, wrong for
        // secrets). One row per (integration, key) pair, e.g.
        // ('pressable', 'client_secret'). `.env` stays the fallback forever;
        // this table only overrides when a row exists.
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 64);
            $table->string('key', 64);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['integration', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
