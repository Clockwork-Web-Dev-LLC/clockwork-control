<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('sections');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Seed initial default template with all seven sections
        DB::table('client_report_templates')->insert([
            'name' => 'Default template',
            'sections' => json_encode(['updates', 'uptime', 'security', 'performance', 'forms', 'traffic', 'backups']),
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('client_reports', function (Blueprint $table) {
            $table->foreignId('template_id')
                ->nullable()
                ->after('site_id')
                ->constrained('client_report_templates')
                ->nullOnDelete();
        });

        Schema::table('client_report_schedules', function (Blueprint $table) {
            $table->foreignId('template_id')
                ->nullable()
                ->after('site_id')
                ->constrained('client_report_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_report_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });

        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });

        Schema::dropIfExists('client_report_templates');
    }
};
