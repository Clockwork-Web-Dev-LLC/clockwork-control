<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('title');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('sections_data');
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('status')->default('generated'); // draft, generated, sent
            $table->timestamp('sent_at')->nullable();
            $table->string('public_token', 64)->unique();
            $table->timestamps();

            $table->index(['site_id', 'period_start', 'period_end']);
        });

        Schema::create('client_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('frequency')->default('monthly'); // weekly, monthly, quarterly
            $table->json('recipients'); // array of emails
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_report_schedules');
        Schema::dropIfExists('client_reports');
    }
};
