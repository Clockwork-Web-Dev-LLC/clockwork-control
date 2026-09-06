<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server snapshot of "what apt updates are pending right now."
 *
 * SpinupWP only exposes a boolean `upgrade_required` flag — it does not tell
 * us how many packages or which ones. This table caches the richer detail we
 * pull via SSH (apt-check + reboot-required.pkgs), so the Updates tab can
 * render "12 updates, 3 security, 2 days behind" without an SSH on every
 * page load. One row per server (latest poll wins) — history is not the
 * goal here, current state is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_update_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();

            $table->timestamp('polled_at');

            // From `/usr/lib/update-notifier/apt-check 2>&1` → "total;security".
            $table->unsignedInteger('total_updates')->default(0);
            $table->unsignedInteger('security_updates')->default(0);

            // From `/var/run/reboot-required` (existence) and
            // `/var/run/reboot-required.pkgs` (newline-separated package list).
            $table->boolean('reboot_required')->default(false);
            $table->json('reboot_required_pkgs')->nullable();

            // 'ok' | 'ssh_failed' | 'parse_failed' — keeps the last error
            // visible in the UI so the operator knows when a poll is stale
            // because of a connectivity problem rather than because the
            // server is genuinely up to date.
            $table->string('poll_status', 16)->default('ok');
            $table->text('poll_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_update_snapshots');
    }
};
