<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google profile avatar URLs from Socialite include a long signed-blob
 * payload (typically 600–1500 chars) that overflows VARCHAR(255). Widen
 * the column to TEXT so the OAuth callback can persist it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('avatar_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->change();
        });
    }
};
