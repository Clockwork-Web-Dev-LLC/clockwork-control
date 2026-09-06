<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerUpdateSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Fleet-wide system updates dashboard. Surfaces the latest
 * `server_update_snapshots` row for every non-ignored server, plus the
 * current update-queue state, and lets the operator queue updates against
 * one server, several selected servers, or every server with packages
 * pending — all from one screen instead of clicking into each detail page.
 *
 * Lives under Settings → Operations because it's a maintenance action,
 * not a configuration screen.
 */
class OperationsUpdatesController extends Controller
{
    /**
     * Cache key for the "a fleet poll is currently running" marker. The value
     * stored is the ISO start timestamp; presence is the signal. TTL bounds
     * the in-progress state in case the background process dies without
     * clearing the marker (poll worst-case is ~3.5min, so 10min is generous).
     */
    public const POLL_MARKER_KEY = 'operations.system_updates.poll_in_progress_since';

    public const POLL_MARKER_TTL_SEC = 600;

    public function index(): View
    {
        $servers = Server::query()
            ->with(['updateSnapshot'])
            ->where('is_ignored', false)
            ->orderBy('name')
            ->get();

        // If a background poll is in flight, count how many servers have
        // already been re-polled since it started so the banner can show
        // progress ("polled 17 / 49"). The poll command writes each snapshot
        // as it finishes, so polled_at > startedAt is a clean per-server
        // "done" signal. When every monitored server has been polled past
        // the marker, drop it — caller will then see a normal page render.
        $pollMarker = Cache::get(self::POLL_MARKER_KEY);
        $pollInProgress = false;
        $polledSinceStart = 0;
        $pollStartedAt = null;
        if (is_string($pollMarker) && $pollMarker !== '') {
            $pollStartedAt = Carbon::parse($pollMarker);
            $polledSinceStart = $servers
                ->filter(fn ($s) => $s->updateSnapshot
                    && $s->updateSnapshot->polled_at
                    && $s->updateSnapshot->polled_at->greaterThanOrEqualTo($pollStartedAt))
                ->count();
            if ($polledSinceStart >= $servers->count()) {
                Cache::forget(self::POLL_MARKER_KEY);
            } else {
                $pollInProgress = true;
            }
        }

        // Roll-up tiles. Sum/max from snapshots only — servers without a
        // snapshot row contribute zero to fleet totals (their boolean
        // `upgrade_required` is shown per-row but doesn't carry a count).
        $okSnaps = $servers
            ->map(fn ($s) => $s->updateSnapshot)
            ->filter(fn ($s) => $s && $s->poll_status === ServerUpdateSnapshot::STATUS_OK);

        $totals = [
            'fleet_size' => $servers->count(),
            'polled_ok' => $okSnaps->count(),
            'never_polled' => $servers->filter(fn ($s) => ! $s->updateSnapshot)->count(),
            'failed_polls' => $servers->filter(fn ($s) => $s->updateSnapshot
                && $s->updateSnapshot->poll_status !== ServerUpdateSnapshot::STATUS_OK)->count(),
            'total_updates' => $okSnaps->sum('total_updates'),
            'security_updates' => $okSnaps->sum('security_updates'),
            'reboot_pending' => $okSnaps->where('reboot_required', true)->count(),
            'queued' => $servers->where('update_status', Server::UPDATE_STATUS_QUEUED)->count(),
            'running' => $servers->where('update_status', Server::UPDATE_STATUS_RUNNING)->count(),
        ];

        return view('operations.server-updates', compact(
            'servers',
            'totals',
            'pollInProgress',
            'polledSinceStart',
            'pollStartedAt',
        ));
    }

    /**
     * Bulk-queue apt-update jobs for the selected servers. Shape mirrors the
     * single-server queue endpoint: same status-transition rules, same
     * `reboot_at` semantics applied to every selected box. Server is silently
     * skipped (with a counted reason) if it's ignored, already running, or
     * already queued — the response surfaces the per-bucket counts so the
     * operator knows what landed and what was passed over.
     */
    public function queueBulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'server_ids' => ['required', 'array', 'min:1'],
            'server_ids.*' => ['integer'],
            'reboot_at' => ['nullable', 'regex:/^\d{1,2}:\d{2}$/'],
            'reboot_immediate' => ['nullable', 'boolean'],
        ]);

        $servers = Server::query()
            ->whereIn('id', $validated['server_ids'])
            ->get();

        $stats = ['queued' => 0, 'skipped_ignored' => 0, 'skipped_staging' => 0, 'skipped_inflight' => 0, 'skipped_no_ssh' => 0];
        $now = Carbon::now();

        $rebootAt = null;
        if (! empty($validated['reboot_at'])) {
            [$h, $m] = array_map('intval', explode(':', $validated['reboot_at']));
            $tz = config('app.timezone', 'UTC');
            $when = Carbon::now($tz)->setTime($h, $m, 0);
            if ($when->isPast()) {
                $when->addDay();
            }
            $rebootAt = $when;
        } elseif ($request->boolean('reboot_immediate', true)) {
            $rebootAt = Carbon::now();
        }

        foreach ($servers as $server) {
            if ($server->is_ignored) {
                $stats['skipped_ignored']++;

                continue;
            }
            if ($server->isStaging()) {
                $stats['skipped_staging']++;

                continue;
            }
            if (in_array($server->update_status, [Server::UPDATE_STATUS_QUEUED, Server::UPDATE_STATUS_RUNNING], true)) {
                $stats['skipped_inflight']++;

                continue;
            }
            if ($server->clockwork_jail_provisioned_at === null && empty($server->ssh_password)) {
                $stats['skipped_no_ssh']++;

                continue;
            }

            $server->update([
                'update_status' => Server::UPDATE_STATUS_QUEUED,
                'update_queued_at' => $now,
                'update_started_at' => null,
                'update_completed_at' => null,
                'scheduled_reboot_at' => $rebootAt,
            ]);

            $stats['queued']++;
        }

        $msg = "Queued {$stats['queued']} server(s).";
        $skips = [];
        if ($stats['skipped_ignored'] > 0) {
            $skips[] = "{$stats['skipped_ignored']} ignored";
        }
        if ($stats['skipped_staging'] > 0) {
            $skips[] = "{$stats['skipped_staging']} staging (never drained by the processor)";
        }
        if ($stats['skipped_inflight'] > 0) {
            $skips[] = "{$stats['skipped_inflight']} already running/queued";
        }
        if ($stats['skipped_no_ssh'] > 0) {
            $skips[] = "{$stats['skipped_no_ssh']} missing SSH credentials";
        }
        if ($skips !== []) {
            $msg .= ' Skipped: '.implode(', ', $skips).'.';
        }
        if (! empty($validated['reboot_at'])) {
            $msg .= " Reboots scheduled for {$validated['reboot_at']} server-local.";
        } elseif ($rebootAt !== null) {
            $msg .= ' Reboots scheduled immediately upon completion.';
        }

        // 303 See Other (instead of back()'s default 302 Found): every
        // browser interprets a 303 as "now do a GET, and DON'T reuse the
        // POST body on subsequent reloads of THIS URL." A 302 sometimes
        // gets remembered as the POST it followed from, so a Cmd+R on the
        // landing page can ask "resubmit?" — and a "remember this choice"
        // checkbox turns the form into an unintentional re-queue loop on
        // every page reload. We caught exactly that in production:
        // queue-bulk was being POSTed every 3-5 minutes, resurrecting
        // already-upgraded servers and pinning dashboard counts. 303
        // makes the URL bar a real GET landing page.
        return redirect()
            ->route('operations.server-updates.index')
            ->with('status', $msg)
            ->setStatusCode(Response::HTTP_SEE_OTHER);
    }

    /**
     * Kick off the fleet poll as a detached background process so the HTTP
     * request returns immediately. The previous synchronous design (~3.5
     * minutes for 50 servers) exceeded PHP's max_execution_time AND blocked
     * `php artisan serve`'s single-threaded server, leaving the user staring
     * at a 30s FatalError page. Now: shell out to a `nohup php artisan … &`,
     * stamp a cache marker so `index()` knows a poll is in flight, redirect
     * back to the dashboard. The view watches the marker, shows a banner
     * ("polled 17 / 49"), and auto-refreshes every 10s until every server's
     * snapshot timestamp catches up to the marker — at which point the
     * marker is dropped and the page renders normally.
     *
     * Concurrency: Cache::add is atomic set-if-absent; a double-click only
     * starts one background process. If a poll is already in flight we
     * redirect with a "still running" notice instead of starting another.
     *
     * Optionally limit to one server via ?server=<id|name>.
     */
    public function refresh(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'server' => ['nullable', 'string'],
            'all' => ['nullable', 'boolean'],
        ]);

        $startedAt = Carbon::now();
        $gotLock = Cache::add(self::POLL_MARKER_KEY, $startedAt->toIso8601String(), self::POLL_MARKER_TTL_SEC);

        if (! $gotLock) {
            return redirect()->route('operations.server-updates.index')
                ->with('status', 'A fleet poll is already running — sit tight, the page will refresh as servers complete.');
        }

        // PHP_BINARY captures the path of the PHP that started the current
        // process. On Homebrew (the local dev setup) that's the versioned
        // Cellar path — e.g. `/opt/homebrew/Cellar/php@8.4/8.4.21/bin/php`
        // — which silently breaks the moment Homebrew updates PHP and moves
        // the binary into a new Cellar directory. The long-running
        // `artisan serve` process keeps holding the OLD path in PHP_BINARY,
        // exec() fails with "no such file or directory," and the background
        // poll never starts. PhpExecutableFinder re-resolves the current
        // PHP binary by walking $PATH, so it survives Homebrew upgrades and
        // production deploys where PHP lives somewhere else entirely.
        $php = (new PhpExecutableFinder)->find();
        if ($php === false) {
            Cache::forget(self::POLL_MARKER_KEY);

            return redirect()->route('operations.server-updates.index')
                ->with('status_error', 'Could not locate the PHP binary to launch the background poll.');
        }

        // Build the artisan command line. Default is --all (the on-demand
        // path wants the full picture, including up-to-date servers); --server
        // narrows to one.
        $cmd = sprintf('%s artisan clockwork:poll-system-updates', escapeshellarg($php));
        if (! empty($validated['server'])) {
            $cmd .= ' --server='.escapeshellarg((string) $validated['server']);
        } else {
            $cmd .= ' --all';
        }

        // Detach: nohup + redirect stdout/stderr to a logfile + trailing `&`
        // are the classic Unix incantation for "PHP can exit, child keeps
        // running." The child re-bootstraps Laravel from artisan as a fresh
        // CLI process, so it doesn't inherit this request's max_execution_time
        // or any request-bound state.
        $logPath = storage_path('logs/operations-poll-bg.log');
        $shell = sprintf(
            '(cd %s && nohup %s < /dev/null > %s 2>&1 &) > /dev/null 2>&1',
            escapeshellarg(base_path()),
            $cmd,
            escapeshellarg($logPath),
        );
        @exec($shell);

        return redirect()->route('operations.server-updates.index')
            ->with('status', 'Fleet poll started in the background — the page will refresh as servers complete.');
    }

    /**
     * GET-handler for the refresh URL. Browsers that land here via
     * back-button or a reloaded POST shouldn't see a 405 — bounce them to
     * the dashboard where they can re-click the button if they want.
     */
    public function refreshRedirect(): RedirectResponse
    {
        return redirect()->route('operations.server-updates.index');
    }
}
