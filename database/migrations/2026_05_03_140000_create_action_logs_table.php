<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic action log — every meaningful thing Clockwork does (plugin updates,
 * SSO launches, manual bans, Companion installs, etc.) lands here as a typed
 * row. Source of truth for "what did we actually do?" reports.
 *
 * Schema is deliberately generic (typed via action_type string, freeform
 * details JSON) so adding a new action type doesn't need a migration.
 *
 * site_id and server_id are both nullable: most actions target one or the
 * other; some (e.g. fleet-wide jobs) target neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type', 64);
            $table->string('target', 255)->nullable();
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->boolean('ok')->default(true);
            $table->text('error')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->string('actor', 64)->default('manual');
            $table->timestamp('ran_at')->index();
            $table->timestamps();

            // Per-site recent-activity card lookup. Composite index covers the
            // most common query: "give me this site's actions newest-first".
            $table->index(['site_id', 'ran_at']);
            // Cross-site filter on action_type for the future month-end report.
            $table->index(['action_type', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
