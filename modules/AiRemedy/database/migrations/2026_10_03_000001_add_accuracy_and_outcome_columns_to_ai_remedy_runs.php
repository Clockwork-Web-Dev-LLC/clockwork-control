<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_remedy_runs', function (Blueprint $table) {
            $table->string('verdict', 16)->nullable()->after('status'); // correct, partial, wrong, unsure
            $table->text('verdict_note')->nullable()->after('verdict');
            $table->foreignId('verdict_by_user_id')->nullable()->after('verdict_note')->constrained('users')->nullOnDelete();
            $table->timestamp('verdict_at')->nullable()->after('verdict_by_user_id');

            $table->string('outcome', 32)->nullable()->after('verdict_at'); // self_resolved, human_resolved, persisted, escalated, unknown
            $table->json('outcome_details')->nullable()->after('outcome');
            $table->timestamp('outcome_evaluated_at')->nullable()->after('outcome_details');

            $table->boolean('is_fixable')->nullable()->after('outcome_evaluated_at');
            $table->boolean('is_maintenance')->nullable()->after('is_fixable');
            $table->string('maintenance_type', 64)->nullable()->after('is_maintenance');
            $table->json('command_decisions')->nullable()->after('maintenance_type');

            $table->index(['outcome', 'outcome_evaluated_at']);
            $table->index('verdict');
        });
    }

    public function down(): void
    {
        Schema::table('ai_remedy_runs', function (Blueprint $table) {
            $table->dropForeign(['verdict_by_user_id']);
            $table->dropIndex(['outcome', 'outcome_evaluated_at']);
            $table->dropIndex(['verdict']);
            $table->dropColumn([
                'verdict',
                'verdict_note',
                'verdict_by_user_id',
                'verdict_at',
                'outcome',
                'outcome_details',
                'outcome_evaluated_at',
                'is_fixable',
                'is_maintenance',
                'maintenance_type',
                'command_decisions',
            ]);
        });
    }
};
