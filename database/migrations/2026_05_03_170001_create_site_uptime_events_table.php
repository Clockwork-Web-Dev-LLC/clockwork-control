<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transition log for per-site uptime — one row per state change (up→down or
 * down→up), NOT one per probe. Keeps the table bounded (a site that's stable
 * for a year has zero rows; a flapping site has at most a few hundred).
 *
 * Reads:
 *   - per-site Status card: latest event for "down since X" / "back up Y ago"
 *   - 24h uptime % calculation: sum the up-windows in the time range
 *   - future SLA report: same data, longer window
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_uptime_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 8); // 'down' or 'up'
            $table->smallInteger('status_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamp('event_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'event_at']);
            $table->index('event_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_uptime_events');
    }
};
