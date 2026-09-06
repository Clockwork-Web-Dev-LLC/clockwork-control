<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site, per-target ignore list for the Updates page. Mirrors ManageWP
 * Orion's "Ignored" tab — once you've decided "this client doesn't want
 * Beaver Builder updated, ever," you say it once and it stops appearing
 * in the Update Selected fan-out.
 *
 * Composite unique on (site_id, target_kind, target_slug) so adding a
 * theme called "akismet" wouldn't collide with a plugin slug called
 * "akismet" — the kind is part of the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_update_ignores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('target_kind', 16);
            $table->string('target_slug', 190)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ignored_at');
            $table->timestamps();

            // Application-enforced uniqueness via composite unique index.
            // Slug nullability here means (site, 'core', null) is also a
            // legal allowlist entry for "ignore core updates on this site."
            $table->unique(['site_id', 'target_kind', 'target_slug'], 'pluign_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_update_ignores');
    }
};
