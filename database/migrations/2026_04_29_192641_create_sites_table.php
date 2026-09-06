<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('wp_path')->nullable();
            $table->string('db_host')->default('127.0.0.1');
            $table->unsignedSmallInteger('db_port')->default(3306);
            $table->string('db_name')->nullable();
            $table->string('db_user')->nullable();
            $table->text('db_password')->nullable();
            $table->string('table_prefix')->default('wp_');
            $table->string('nginx_access_log_path')->nullable();
            $table->boolean('is_wordpress')->default(false);
            $table->boolean('wordfence_enabled')->default(false);
            $table->boolean('llar_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
