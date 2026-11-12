<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The spend ledger: one row per paid API call, with the cost the
        // provider itself reported for it.
        Schema::create('seo_api_usage', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            // Which part of the suite spent it (rank_tracking, site_audit,
            // ...). Budgets are capped per feature.
            $table->string('feature', 40);
            $table->string('endpoint', 191);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            // Set when a person (or Claude acting as them) asked for it;
            // null for scheduled jobs.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('tasks')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('status', 20);
            $table->string('error', 480)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['provider', 'created_at']);
            $table->index(['feature', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_api_usage');
    }
};
