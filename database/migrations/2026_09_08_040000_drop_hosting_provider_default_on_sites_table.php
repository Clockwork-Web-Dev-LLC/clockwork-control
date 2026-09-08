<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `hosting_provider` defaulted to 'spinupwp', so any Site row created
     * without explicitly specifying it silently became a SpinupWP site —
     * which then fell straight into the orphan-sites query the moment
     * spinupwp_id was null. Every current creation path (ImportSpinupWp,
     * ImportPressable, ImportGridPane, SiteFactory) now sets it explicitly,
     * so the default no longer does any useful work — dropping it means a
     * future creation path that forgets it fails loudly (NOT NULL
     * violation) instead of silently mislabeling the site.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('hosting_provider', 32)->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('hosting_provider', 32)->default('spinupwp')->change();
        });
    }
};
