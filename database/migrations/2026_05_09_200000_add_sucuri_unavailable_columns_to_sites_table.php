<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('sucuri_unavailable_at')->nullable()->after('uptime_ignore_reason');
            $table->string('sucuri_unavailable_reason', 200)->nullable()->after('sucuri_unavailable_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['sucuri_unavailable_at', 'sucuri_unavailable_reason']);
        });
    }
};
