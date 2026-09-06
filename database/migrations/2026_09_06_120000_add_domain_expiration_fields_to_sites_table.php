<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('domain_expires_at')->nullable()->after('cert_state_changed_at');
            $table->string('domain_registrar')->nullable()->after('domain_expires_at');
            $table->string('domain_rdap_status')->nullable()->after('domain_registrar');
            $table->timestamp('domain_rdap_checked_at')->nullable()->after('domain_rdap_status');
            $table->string('domain_rdap_error')->nullable()->after('domain_rdap_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'domain_expires_at',
                'domain_registrar',
                'domain_rdap_status',
                'domain_rdap_checked_at',
                'domain_rdap_error',
            ]);
        });
    }
};
