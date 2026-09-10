<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_uptime_events', function (Blueprint $table) {
            $table->boolean('is_sla_exempt')->default(false)->after('diagnosis');
            $table->string('exemption_reason', 64)->nullable()->after('is_sla_exempt');
            $table->text('exemption_notes')->nullable()->after('exemption_reason');
            $table->timestamp('exempted_at')->nullable()->after('exemption_notes');
            $table->string('exempted_by', 128)->nullable()->after('exempted_at');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('uptime_sla_exempt')->default(false)->after('uptime_ignore_reason');
            $table->string('uptime_exemption_reason', 64)->nullable()->after('uptime_sla_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('site_uptime_events', function (Blueprint $table) {
            $table->dropColumn([
                'is_sla_exempt',
                'exemption_reason',
                'exemption_notes',
                'exempted_at',
                'exempted_by',
            ]);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'uptime_sla_exempt',
                'uptime_exemption_reason',
            ]);
        });
    }
};
