<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('cert_source', 32)->default('none')->after('llar_enabled');
            $table->timestamp('cert_expires_at')->nullable()->after('cert_source');
            $table->timestamp('cert_renews_at')->nullable()->after('cert_expires_at');
            $table->text('cert_notes')->nullable()->after('cert_renews_at');

            $table->index('cert_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['cert_expires_at']);
            $table->dropColumn(['cert_source', 'cert_expires_at', 'cert_renews_at', 'cert_notes']);
        });
    }
};
