<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache of the most recent contact-form detection result — full list of
     * {id, title, page_url} objects so the per-site Forms tab's "+ Add form"
     * dropdown can show every available form, not just the auto-pick. Written
     * by clockwork:detect-contact-forms; read by the Forms tab.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('detected_forms')->nullable()->after('contact_forms_detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('detected_forms');
        });
    }
};
