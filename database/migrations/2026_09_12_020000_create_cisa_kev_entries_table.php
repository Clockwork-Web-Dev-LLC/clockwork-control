<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror of the CISA Known Exploited Vulnerabilities (KEV) catalog:
     * https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json
     *
     * Refreshed daily by `clockwork:refresh-cisa-kev`. Used to highlight active
     * in-the-wild exploitation of CVEs present in installed plugins.
     */
    public function up(): void
    {
        Schema::create('cisa_kev_entries', function (Blueprint $table) {
            $table->id();
            $table->string('cve', 64)->unique();
            $table->string('vendor_project', 255);
            $table->string('product', 255);
            $table->date('date_added')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cisa_kev_entries');
    }
};
