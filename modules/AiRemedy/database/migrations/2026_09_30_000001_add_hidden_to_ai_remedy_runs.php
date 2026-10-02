<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_remedy_runs', function (Blueprint $table) {
            // Soft "dismiss" from the incident log without deleting the record.
            $table->timestamp('hidden_at')->nullable()->after('completed_at');
            $table->foreignId('hidden_by_user_id')->nullable()->after('hidden_at')->constrained('users')->nullOnDelete();
            $table->index(['hidden_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_remedy_runs', function (Blueprint $table) {
            $table->dropIndex(['hidden_at', 'created_at']);
            $table->dropConstrainedForeignId('hidden_by_user_id');
            $table->dropColumn('hidden_at');
        });
    }
};
