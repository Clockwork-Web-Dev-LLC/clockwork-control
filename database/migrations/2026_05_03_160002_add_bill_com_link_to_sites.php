<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link sites to Bill.com customers + add a manual override that the sync
 * respects when deciding whether to flip care_plan_enabled.
 *
 * - bill_com_customer_id: FK to bill_com_customers (string, no constraint
 *   because the customer cache may not have synced yet when a site row is
 *   created).
 * - bill_com_customer_name: denormalised for display, refreshed each sync.
 * - bill_com_linked_via_invoice: which invoice proved the link (e.g. INV-21118).
 *   Auditability — answers "why did Clockwork think this site belongs to that
 *   customer?"
 * - bill_com_linked_at: when the link was made / last refreshed.
 * - care_plan_override: NULL = sync may write care_plan_enabled freely;
 *   true/false = human set this manually, sync must NOT overwrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('bill_com_customer_id', 64)->nullable()->after('care_plan_enabled');
            $table->string('bill_com_customer_name', 255)->nullable()->after('bill_com_customer_id');
            $table->string('bill_com_linked_via_invoice', 64)->nullable()->after('bill_com_customer_name');
            $table->timestamp('bill_com_linked_at')->nullable()->after('bill_com_linked_via_invoice');
            $table->boolean('care_plan_override')->nullable()->after('bill_com_linked_at');

            $table->index('bill_com_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['bill_com_customer_id']);
            $table->dropColumn([
                'bill_com_customer_id',
                'bill_com_customer_name',
                'bill_com_linked_via_invoice',
                'bill_com_linked_at',
                'care_plan_override',
            ]);
        });
    }
};
