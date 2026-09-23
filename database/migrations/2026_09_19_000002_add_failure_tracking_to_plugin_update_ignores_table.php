<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_update_ignores', function (Blueprint $table) {
            $table->string('source', 32)->default('manual')->after('target_slug');
            $table->unsignedInteger('failure_count')->nullable()->after('source');
            $table->text('last_error')->nullable()->after('failure_count');
            $table->boolean('client_visible')->default(false)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_update_ignores', function (Blueprint $table) {
            $table->dropColumn(['source', 'failure_count', 'last_error', 'client_visible']);
        });
    }
};
