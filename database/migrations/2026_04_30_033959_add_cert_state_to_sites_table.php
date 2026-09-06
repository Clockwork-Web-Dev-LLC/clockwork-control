<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('cert_state', 16)->nullable()->after('cert_notes');
            $table->timestamp('cert_state_changed_at')->nullable()->after('cert_state');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['cert_state', 'cert_state_changed_at']);
        });
    }
};
