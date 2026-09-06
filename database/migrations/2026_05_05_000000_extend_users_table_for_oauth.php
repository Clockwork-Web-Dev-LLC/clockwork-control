<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the default Laravel `users` table to support Google OAuth as the
 * sole identity provider, plus an operator-controlled allowlist semantic.
 *
 * - revoked_at: when set, this user can't sign in. Soft-revoke instead of
 *   delete so we keep audit history (action_logs reference user_id).
 * - last_login_at: populated on successful Google callback.
 * - google_id: Google's stable subject ID. Email can change; this can't.
 *   Used as a defense-in-depth check on subsequent logins.
 * - avatar_url: Google's avatar URL, refreshed on each login. Cosmetic.
 * - password is made nullable: we never use it. The column stays so we don't
 *   collide with Laravel's Authenticatable contract assumptions.
 *
 * Drops password_reset_tokens — we have nothing to reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->timestamp('revoked_at')->nullable()->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('revoked_at');
            $table->string('google_id')->nullable()->unique()->after('last_login_at');
            $table->string('avatar_url')->nullable()->after('google_id');
        });

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        // password_reset_tokens recreated to match Laravel's stock shape.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'google_id', 'last_login_at', 'revoked_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
