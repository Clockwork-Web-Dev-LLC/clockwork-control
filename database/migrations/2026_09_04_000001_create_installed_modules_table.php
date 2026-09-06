<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installed_modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_id', 64)->unique();
            $table->string('name', 128);
            $table->enum('source', ['bundled', 'marketplace', 'custom'])->default('bundled');
            $table->string('provider_class')->nullable();
            $table->string('repo_url')->nullable();
            $table->string('version')->nullable();
            $table->boolean('enabled')->default(true);
            $table->enum('status', ['active', 'pending_review', 'installing', 'failed', 'removed'])->default('active');
            $table->text('install_log')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installed_modules');
    }
};
