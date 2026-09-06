<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // unknown | proxied | dns_only | not_using
            $table->string('cloudflare_state', 16)->default('unknown')->after('cert_state_changed_at');
            $table->timestamp('cloudflare_checked_at')->nullable()->after('cloudflare_state');
            // Stash what we resolved for debugging / display.
            $table->string('resolved_a_record', 64)->nullable()->after('cloudflare_checked_at');
            $table->string('resolved_ns_record', 128)->nullable()->after('resolved_a_record');

            $table->index('cloudflare_state');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['cloudflare_state']);
            $table->dropColumn(['cloudflare_state', 'cloudflare_checked_at', 'resolved_a_record', 'resolved_ns_record']);
        });
    }
};
