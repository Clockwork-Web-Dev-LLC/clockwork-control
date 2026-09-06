<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flip the auto-update model from "opt-out" (every care-plan site is in
     * unless paused) to "opt-in" (every care-plan site is OUT unless the
     * operator has explicitly enabled it on /updates/care-plan).
     *
     * Two parts:
     *   1. Change the column default to true so any newly-flagged care-plan
     *      site lands paused.
     *   2. Backfill: set auto_updates_paused = true on every existing site,
     *      with a reason that makes it clear this was the bulk opt-in flip
     *      and not a per-site pause decision.
     */
    public function up(): void
    {
        Schema::table('sites', function ($table) {
            $table->boolean('auto_updates_paused')->default(true)->change();
        });

        // Set every site's auto_updates_paused to true. Care-plan sites are
        // the only ones that matter for the nightly loop, but flipping all
        // rows keeps the column default and existing data consistent.
        // Reason annotated so the operator can tell "this was the initial
        // opt-in flip" vs "I paused this on purpose".
        DB::table('sites')
            ->where('auto_updates_paused', false)
            ->update([
                'auto_updates_paused' => true,
                'auto_updates_paused_reason' => 'Initial opt-in flip — enable per-site at /updates/care-plan',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('sites', function ($table) {
            $table->boolean('auto_updates_paused')->default(false)->change();
        });

        DB::table('sites')
            ->where('auto_updates_paused_reason', 'Initial opt-in flip — enable per-site at /updates/care-plan')
            ->update([
                'auto_updates_paused' => false,
                'auto_updates_paused_reason' => null,
                'updated_at' => now(),
            ]);
    }
};
