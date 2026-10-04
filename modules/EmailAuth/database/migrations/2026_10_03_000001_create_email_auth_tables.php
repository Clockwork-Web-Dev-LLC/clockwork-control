<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_auth_checks', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('overall_status', 32)->index(); // pass, warn, fail, unknown
            $table->string('spf_status', 32)->default('unknown');
            $table->text('spf_record')->nullable();
            $table->unsignedSmallInteger('spf_lookup_count')->default(0);
            $table->string('dmarc_status', 32)->default('unknown');
            $table->string('dmarc_policy', 32)->nullable();
            $table->text('dmarc_record')->nullable();
            $table->string('dkim_status', 32)->default('unknown');
            $table->json('dkim_selectors_found')->nullable();
            $table->boolean('mx_present')->default(true);
            $table->json('findings')->nullable();
            $table->timestamp('checked_at')->index();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['domain', 'checked_at']);
        });

        Schema::create('email_auth_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->json('custom_dkim_selectors')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->string('ignored_reason')->nullable();
            $table->string('last_overall_status', 32)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_auth_domains');
        Schema::dropIfExists('email_auth_checks');
    }
};
