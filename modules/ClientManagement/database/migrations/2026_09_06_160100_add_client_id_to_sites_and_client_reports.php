<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('client_email')
                ->constrained('clients')
                ->nullOnDelete();
        });

        Schema::table('client_reports', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('site_id')
                ->constrained('clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
