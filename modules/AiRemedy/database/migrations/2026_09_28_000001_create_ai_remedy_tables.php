<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_remedy_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_type')->default('server_spike'); // server_spike, site_downtime, manual_audit
            $table->string('status')->default('analyzed'); // pending, analyzed, executing, resolved, unfixable, rejected, failed
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor')->default('manual'); // manual, autonomous
            $table->string('model_used')->default('anthropic/claude-3.5-sonnet');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('total_cost_usd', 8, 4)->default(0.0000);
            $table->string('trigger_reason')->nullable();
            $table->json('telemetry_snapshot')->nullable();
            $table->text('diagnosis_summary')->nullable();
            $table->text('root_cause')->nullable();
            $table->string('safety_tier')->default('tier_1_safe'); // tier_1_safe, tier_2_cautious, tier_3_prohibited, unfixable
            $table->json('proposed_commands')->nullable();
            $table->json('approved_commands')->nullable();
            $table->longText('execution_output')->nullable();
            $table->json('before_metrics')->nullable();
            $table->json('after_metrics')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
            $table->index(['site_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('trigger_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_remedy_runs');
    }
};
