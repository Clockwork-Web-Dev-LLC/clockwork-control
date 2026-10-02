<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_recipients', function (Blueprint $table) {
            $table->string('type', 32)->default('team')->index()->after('id');
            $table->string('company', 120)->nullable()->index()->after('name');
            $table->string('email', 255)->nullable()->after('phone');
            $table->boolean('notify_sms')->default(true)->after('email_fallback');
            $table->boolean('notify_email')->default(false)->after('notify_sms');
            $table->string('phone', 32)->nullable()->change();
        });

        // Backfill email from email_fallback if present
        DB::table('notification_recipients')
            ->whereNotNull('email_fallback')
            ->whereNull('email')
            ->update(['email' => DB::raw('email_fallback')]);
    }

    public function down(): void
    {
        Schema::table('notification_recipients', function (Blueprint $table) {
            $table->dropColumn(['type', 'company', 'email', 'notify_sms', 'notify_email']);
            $table->string('phone', 32)->nullable(false)->change();
        });
    }
};
