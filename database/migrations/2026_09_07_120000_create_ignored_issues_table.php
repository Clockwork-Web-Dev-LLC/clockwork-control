<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignored_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_type')->index(); // e.g. 'seo_indexability'
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['issue_type', 'site_id'], 'unique_issue_per_site');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ignored_issues');
    }
};
