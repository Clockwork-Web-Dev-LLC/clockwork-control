<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('uptime_maintenance_since')->nullable()->after('uptime_down_since');
        });

        Schema::table('site_uptime_events', function (Blueprint $table) {
            $table->string('event_type', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('uptime_maintenance_since');
        });

        Schema::table('site_uptime_events', function (Blueprint $table) {
            $table->string('event_type', 8)->change();
        });
    }
};
