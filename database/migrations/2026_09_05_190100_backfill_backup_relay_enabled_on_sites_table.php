<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Backfill existing Pressable care-plan sites to have backup_relay_enabled = true.
     */
    public function up(): void
    {
        DB::table('sites')
            ->where('hosting_provider', 'pressable')
            ->where('care_plan_enabled', true)
            ->update(['backup_relay_enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op rollback
    }
};
