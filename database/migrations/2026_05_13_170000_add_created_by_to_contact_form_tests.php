<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance for each contact_form_tests row. 'agency' = an operator
     * added it from the Clockwork dashboard. 'client' = the wp-admin user
     * on the customer's site subscribed it via Companion's Forms tab and
     * the nightly sync command (`clockwork:sync-companion-form-
     * subscriptions`) reconciled it into this table.
     *
     * Used both for the per-row badge on the Forms UI and to drive
     * reconciliation semantics — the sync command only adds/removes rows
     * whose provenance is 'client' (it never touches agency-added rows).
     */
    public function up(): void
    {
        Schema::table('contact_form_tests', function (Blueprint $table) {
            $table->enum('created_by', ['agency', 'client'])
                ->default('agency')
                ->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('contact_form_tests', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};
