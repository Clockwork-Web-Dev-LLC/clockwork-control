<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_update_failure_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('target_kind', 16); // plugin | theme
            $table->string('target_slug', 190);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('last_target_version')->nullable();
            $table->string('last_from_version')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->foreignId('last_job_id')->nullable()->constrained('plugin_update_jobs')->nullOnDelete();
            $table->timestamp('ignored_at')->nullable();
            $table->foreignId('ignore_id')->nullable()->constrained('plugin_update_ignores')->nullOnDelete();
            $table->timestamp('companion_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'target_kind', 'target_slug'], 'pufs_site_kind_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_update_failure_streaks');
    }
};
