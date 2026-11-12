<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scheduled report stores every recipient comma-joined here; eight or
     * so long addresses overflow a varchar(255), and under MySQL's strict
     * mode the insert fails, so that site's report crashes every day.
     */
    public function up(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->text('client_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->string('client_email')->nullable()->change();
        });
    }
};
