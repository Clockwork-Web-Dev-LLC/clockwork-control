<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_form_test_runs', function (Blueprint $table) {
            // Nullable so historical rows (which predate the per-form table)
            // can still live in the table without a forced backfill. The
            // backfill migration below populates this where unambiguous.
            $table->foreignId('contact_form_test_id')
                ->nullable()
                ->after('site_id')
                ->constrained('contact_form_tests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_form_test_runs', function (Blueprint $table) {
            $table->dropForeign(['contact_form_test_id']);
            $table->dropColumn('contact_form_test_id');
        });
    }
};
