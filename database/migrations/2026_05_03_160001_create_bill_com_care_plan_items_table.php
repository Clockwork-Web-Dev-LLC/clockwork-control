<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local cache + classification of Bill.com Items (the product/service catalog
 * that line items reference via itemId).
 *
 * is_care_plan is auto-set by name regex during sync (default /care plan/i),
 * but can be manually overridden — the regex_set boolean records whether it
 * was the regex (true) or human (false) that set the flag, so a re-classified
 * item doesn't get clobbered on the next sync.
 *
 * Read by: CarePlanSyncService (which invoices count as care-plan-revealing),
 * future "manage Bill.com items" admin page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_com_care_plan_items', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 255);
            $table->boolean('is_care_plan')->default(false);
            $table->boolean('regex_set')->default(true); // true = regex matched, false = manual override
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_com_care_plan_items');
    }
};
