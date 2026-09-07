<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_report_schedules', function (Blueprint $table) {
            $table->string('delivery_mode')->default('auto')->after('frequency'); // auto, draft
        });
    }

    public function down(): void
    {
        Schema::table('client_report_schedules', function (Blueprint $table) {
            $table->dropColumn('delivery_mode');
        });
    }
};
