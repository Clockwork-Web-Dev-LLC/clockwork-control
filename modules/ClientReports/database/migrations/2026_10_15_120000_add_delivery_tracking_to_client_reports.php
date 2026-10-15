<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            // Lets a scheduled run find and resume its own unfinished report
            // instead of compiling (and re-sending) a fresh one.
            $table->foreignId('schedule_id')
                ->nullable()
                ->after('template_id')
                ->constrained('client_report_schedules')
                ->nullOnDelete();

            $table->json('sent_to')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropColumn('sent_to');
        });
    }
};
