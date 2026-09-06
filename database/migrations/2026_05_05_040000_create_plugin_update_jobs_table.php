<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live job tracker for the fleet Updates page.
 *
 * Despite the name "plugin_update_jobs", this table covers all four update
 * kinds (plugins, themes, WP core, translations). The `target_kind` column
 * distinguishes them. Single table because the schema is identical and
 * splitting four ways would explode the join surface for the live progress
 * UI ("which sites have anything in flight RIGHT NOW?").
 *
 * Lifecycle: a row is born `pending`, the worker flips it `running` while
 * the Companion HTTP call is in flight, then `complete` or `failed` on
 * return. `skipped` covers cases where the row is enqueued but no longer
 * applicable by the time the worker runs (e.g. someone allowlisted the
 * pair, or the snapshot already shows the target version installed).
 *
 * action_logs is the immutable archive — this table is the live tracker.
 * On completion the worker writes BOTH (this row updates + a fresh
 * action_logs row via ActionLogger::record). They're allowed to drift on
 * the audit-trail front: action_logs is canonical for "what did we do?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_update_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // 'plugin' | 'theme' | 'core' | 'translation'. String not enum
            // so future additions don't need a migration.
            $table->string('target_kind', 16);

            // Slug is null for core (only one core to update) and translation
            // (we update everything pending in one call, no per-component split).
            $table->string('target_slug', 190)->nullable();

            // Captured at enqueue time. Lets the progress UI keep showing
            // "Beaver Builder" even if the site goes offline mid-batch.
            $table->string('target_name', 190)->nullable();

            // pending | running | complete | failed | skipped | cancelled
            $table->string('status', 16)->default('pending');

            $table->string('before_version', 64)->nullable();
            $table->string('target_version', 64)->nullable();
            $table->string('after_version', 64)->nullable();
            $table->boolean('was_active')->nullable();
            $table->boolean('reactivated')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->text('error')->nullable();
            $table->json('messages')->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Groups all jobs from one bulk-update click — drives the page
            // progress bar. UUID so we can pass it in the URL safely.
            $table->uuid('batch_id')->index();

            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // "Is there a live job for this (site, plugin) row?" — drives the
            // disabled-button state on every per-row Update button.
            $table->index(['site_id', 'target_kind', 'target_slug', 'status'], 'pluj_site_kind_slug_status_idx');
            $table->index(['batch_id', 'status'], 'pluj_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_update_jobs');
    }
};
