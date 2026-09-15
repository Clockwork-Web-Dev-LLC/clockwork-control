<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill missing server_id on review_queue and blocked_ips from sites.server_id.
     *
     * In earlier ingest cycles, LLAR lockouts were sometimes recorded with site_id
     * populated but server_id null. This associates those historical entries with their
     * site's server so they don't fail repeat-ban processing with 'server record missing'.
     */
    public function up(): void
    {
        DB::transaction(function () {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('
                    UPDATE review_queue
                    SET server_id = (SELECT server_id FROM sites WHERE sites.id = review_queue.site_id)
                    WHERE server_id IS NULL
                      AND site_id IN (SELECT id FROM sites WHERE server_id IS NOT NULL)
                ');

                DB::statement('
                    UPDATE blocked_ips
                    SET server_id = (SELECT server_id FROM sites WHERE sites.id = blocked_ips.site_id)
                    WHERE server_id IS NULL
                      AND site_id IN (SELECT id FROM sites WHERE server_id IS NOT NULL)
                ');
            } else {
                DB::statement('
                    UPDATE review_queue
                    JOIN sites ON review_queue.site_id = sites.id
                    SET review_queue.server_id = sites.server_id
                    WHERE review_queue.server_id IS NULL
                      AND sites.server_id IS NOT NULL
                ');

                DB::statement('
                    UPDATE blocked_ips
                    JOIN sites ON blocked_ips.site_id = sites.id
                    SET blocked_ips.server_id = sites.server_id
                    WHERE blocked_ips.server_id IS NULL
                      AND sites.server_id IS NOT NULL
                ');
            }
        });
    }

    public function down(): void
    {
        // One-time data repair backfill; reversible down is a no-op.
    }
};
