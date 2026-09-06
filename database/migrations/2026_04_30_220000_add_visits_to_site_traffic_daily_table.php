<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_traffic_daily', function (Blueprint $table) {
            // WP-Engine-style visit count: DISTINCT IPs per UTC day, excluding 403s,
            // static assets, and known bots (allowed_bots table). Distinct from
            // `unique_ips` which counts ALL IPs including bots and 403s.
            $table->unsignedBigInteger('visits')->default(0)->after('unique_ips');
        });
    }

    public function down(): void
    {
        Schema::table('site_traffic_daily', function (Blueprint $table) {
            $table->dropColumn('visits');
        });
    }
};
