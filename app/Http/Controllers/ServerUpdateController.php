<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Servers\ServerUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ServerUpdateController extends Controller
{
    /**
     * Mark a server as queued for update. The actual SSH work happens off the request
     * path in clockwork:process-server-updates (every minute via the scheduler).
     */
    public function queue(Request $request, Server $server): RedirectResponse
    {
        if ($server->is_ignored) {
            return back()->with('status_error', "Cannot run updates on ignored server {$server->name}.");
        }

        if (in_array($server->update_status, [Server::UPDATE_STATUS_QUEUED, Server::UPDATE_STATUS_RUNNING], true)) {
            return back()->with('status_error', 'An update is already queued or running for this server.');
        }

        $validated = $request->validate([
            'reboot_at' => ['nullable', 'regex:/^\d{1,2}:\d{2}$/'],
        ]);

        $rebootAt = null;
        if (! empty($validated['reboot_at'])) {
            // Store as a timestamp at server-local hour today (or next-day if past).
            // We don't run the reboot scheduling — the SSH script does that with `shutdown -r HH:MM`.
            // This stored value is only for surfacing "scheduled for X" in the UI.
            [$h, $m] = array_map('intval', explode(':', $validated['reboot_at']));
            $tz = $server->timezone ?: config('app.timezone', 'UTC');
            $when = Carbon::now($tz)->setTime($h, $m, 0);
            if ($when->isPast()) {
                $when->addDay();
            }
            $rebootAt = $when;
        }

        $server->update([
            'update_status' => Server::UPDATE_STATUS_QUEUED,
            'update_queued_at' => Carbon::now(),
            'update_started_at' => null,
            'update_completed_at' => null,
            'scheduled_reboot_at' => $rebootAt,
        ]);

        return back()->with(
            'status',
            $rebootAt
                ? "Update queued for {$server->name}. Reboot will be scheduled for {$rebootAt->format('H:i T')}. The processor runs every minute."
                : "Update queued for {$server->name}. The processor runs every minute."
        );
    }

    /**
     * Reboot a server — runs `sudo shutdown -r` over SSH, decoupled from the apt-get path.
     * Used when the box has /var/run/reboot-required from a prior upgrade and just needs to
     * be cycled, without re-running apt.
     */
    public function reboot(Request $request, Server $server, ServerUpdater $updater): RedirectResponse|JsonResponse
    {
        // The /issues page rebooting-from-list panel sends Accept: application/json
        // so it can fade the row in place; the per-server detail page's form posts
        // a regular HTML request and expects a redirect with flash status. One
        // method handles both shapes.
        $wantsJson = $request->expectsJson();

        $error = function (string $message) use ($wantsJson): RedirectResponse|JsonResponse {
            return $wantsJson
                ? response()->json(['ok' => false, 'message' => $message], 422)
                : back()->with('status_error', $message);
        };

        if ($server->is_ignored) {
            return $error("Cannot reboot ignored server {$server->name}.");
        }

        if ($server->clockwork_jail_provisioned_at === null && empty($server->ssh_password)) {
            return $error("Server {$server->name} has no SSH credentials.");
        }

        $validated = $request->validate([
            'reboot_at' => ['nullable', 'regex:/^\d{1,2}:\d{2}$/'],
        ]);

        $atHHMM = ! empty($validated['reboot_at']) ? $validated['reboot_at'] : null;

        $result = $updater->reboot($server, $atHHMM);

        if (! $result['ok']) {
            return $error($result['message'].' — '.trim($result['output']));
        }

        // Surface the scheduled time to the UI. For a "now" reboot we still set it so users
        // see the timestamp; the value is approximate (+1 minute from issue).
        $tz = $server->timezone ?: config('app.timezone', 'UTC');
        $scheduledFor = $atHHMM
            ? Carbon::now($tz)->setTime(...array_map('intval', explode(':', $atHHMM)))
            : Carbon::now()->addMinute();

        if ($atHHMM && $scheduledFor->isPast()) {
            $scheduledFor->addDay();
        }

        // Optimistic clear: a successful `shutdown -r` will land us on a kernel where
        // /var/run/reboot-required no longer exists. Don't make the user stare at a
        // stale "Reboot required" badge while the box reboots — null last_polled_at
        // forces the next poll to refresh from the truth.
        $server->update([
            'scheduled_reboot_at' => $scheduledFor,
            'reboot_required' => false,
            'last_polled_at' => null,
        ]);

        $successMessage = $result['message'].' Click "Recheck state" once the box is back to confirm.';

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'message' => $successMessage,
                'scheduled_for' => $scheduledFor->toIso8601String(),
            ]);
        }

        return back()->with('status', $successMessage);
    }

    /**
     * Probe the server right now for /var/run/reboot-required and update the cached
     * reboot_required flag. Used by the "Recheck state" button after a reboot — gives
     * the UI a self-service path to converge without waiting for the scheduler.
     */
    public function probeReboot(Server $server, ServerUpdater $updater): JsonResponse
    {
        $state = $updater->probeRebootState($server);

        if ($state === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Server is unreachable over SSH right now. Try again in a minute — it may still be coming back up.',
            ], 422);
        }

        $server->update([
            'reboot_required' => $state['required'],
            'last_ssh_ok_at' => Carbon::now(),
        ]);

        $uptimeHuman = $this->humanizeUptime($state['uptime_seconds']);

        // Build a message that combines truth (/var/run/reboot-required) with context
        // (uptime). A short-uptime box with reboot-required set means a NEW kernel landed
        // since the recent reboot and the user needs to cycle again.
        $message = match (true) {
            ! $state['required'] && $state['uptime_seconds'] < 600 => "Rebooted {$uptimeHuman} ago — clean, no reboot pending.",
            ! $state['required'] => "Up {$uptimeHuman} — no reboot pending.",
            $state['uptime_seconds'] < 600 => "Rebooted {$uptimeHuman} ago, but a new kernel is already pending — another reboot needed.",
            default => "Up {$uptimeHuman} — /var/run/reboot-required is set, reboot needed.",
        };

        return response()->json([
            'ok' => true,
            'reboot_required' => $state['required'],
            'uptime_seconds' => $state['uptime_seconds'],
            'message' => $message,
        ]);
    }

    private function humanizeUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }
        if ($seconds < 86400) {
            $h = floor($seconds / 3600);
            $m = floor(($seconds % 3600) / 60);

            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }
        $d = floor($seconds / 86400);
        $h = floor(($seconds % 86400) / 3600);

        return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
    }

    /**
     * Cancel a previously-scheduled reboot (`shutdown -c`). Only works if shutdown(8)
     * hasn't already passed the point of no return.
     */
    public function cancelReboot(Server $server, ServerUpdater $updater): RedirectResponse
    {
        if ($server->is_ignored) {
            return back()->with('status_error', "Cannot manage reboots on ignored server {$server->name}.");
        }

        $result = $updater->cancelReboot($server);

        if (! $result['ok']) {
            return back()->with('status_error', $result['message'].' — '.trim($result['output']));
        }

        $server->update(['scheduled_reboot_at' => null]);

        return back()->with('status', "Cancelled scheduled reboot on {$server->name}.");
    }

    /**
     * Cancel a queued update (only works while still queued — running updates have to finish).
     */
    public function cancel(Server $server): RedirectResponse
    {
        if ($server->update_status !== Server::UPDATE_STATUS_QUEUED) {
            return back()->with('status_error', 'Update is not in the queued state — cannot cancel.');
        }

        $server->update([
            'update_status' => null,
            'update_queued_at' => null,
            'scheduled_reboot_at' => null,
        ]);

        return back()->with('status', "Cancelled queued update for {$server->name}.");
    }
}
