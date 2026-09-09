<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\CloudProvider\CloudProviderRegistry;
use App\Services\Monitoring\CpuStatusClassifier;
use App\Services\Process\BackgroundArtisan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\ModuleStateResolver;
use Modules\SpinupWp\SpinupWpClient;

class ServersController extends Controller
{
    public function create(): View
    {
        return view('dashboard.server-create');
    }

    /**
     * Run `clockwork:import-spinupwp` on demand. Picks up newly-added servers
     * and reflects site moves between servers immediately, without waiting for
     * the daily 03:30 schedule. Idempotent and fleet-wide; same command the
     * scheduler runs.
     */
    public function refreshFromSpinupWp(Request $request): RedirectResponse
    {
        if (! app(ModuleStateResolver::class)->isEnabled('spinupwp')) {
            return back()->with('status_error', 'SpinupWP is not enabled for this fleet.');
        }

        $result = $this->startImportInBackground(
            'fleet.import_spinupwp',
            ['clockwork:import-spinupwp', 'clockwork:poll-servers'],
            'spinupwp-import-bg',
        );

        if ($result['ok']) {
            return back()->with('status', $result['summary']);
        }

        return back()->with('status_error', $result['summary']);
    }

    /**
     * Run `clockwork:import-gridpane` on demand — the GridPane counterpart
     * to refreshFromSpinupWp() above, for servers managed through GridPane
     * instead of SpinupWP.
     */
    public function refreshFromGridPane(Request $request): RedirectResponse
    {
        if (! app(ModuleStateResolver::class)->isEnabled('gridpane')) {
            return back()->with('status_error', 'GridPane is not enabled for this fleet.');
        }

        $result = $this->startImportInBackground(
            'fleet.import_gridpane',
            ['clockwork:import-gridpane', 'clockwork:poll-servers'],
            'gridpane-import-bg',
        );

        if ($result['ok']) {
            return back()->with('status', $result['summary']);
        }

        return back()->with('status_error', $result['summary']);
    }

    /**
     * Import then poll, detached. The previous inline Artisan::call pair
     * blocked the request for the whole fleet (import + metrics poll).
     *
     * @param  list<string>  $commands
     * @return array{ok: bool, summary: string}
     */
    protected function startImportInBackground(string $lockKey, array $commands, string $logBasename): array
    {
        $result = app(BackgroundArtisan::class)->start($lockKey, $commands, 900, $logBasename);

        if ($result->alreadyRunning()) {
            return ['ok' => true, 'summary' => 'A refresh is already running in the background.'];
        }

        if ($result->failed()) {
            return ['ok' => false, 'summary' => $result->error ?? 'Could not start the background refresh.'];
        }

        $label = str_contains($commands[0], 'gridpane') ? 'GridPane' : 'SpinupWP';

        return ['ok' => true, 'summary' => "{$label} refresh started in the background (import, then fleet poll). Refresh this page in a minute."];
    }

    public function store(Request $request): RedirectResponse
    {
        $defaultUser = (string) config('clockwork.ssh.default_user');
        $defaultPort = (int) config('clockwork.ssh.default_port', 22);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255'],
            'ssh_user' => ['nullable', 'string', 'max:255'],
            'ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'ssh_password' => ['nullable', 'string'],
            'is_ignored' => ['nullable', 'boolean'],
            'ignore_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $server = new Server;
        $server->name = $validated['name'];
        $server->hostname = $validated['hostname'];
        $server->ssh_user = $validated['ssh_user'] ?: $defaultUser;
        $server->ssh_port = $validated['ssh_port'] ?: $defaultPort;
        $server->status = Server::STATUS_UNKNOWN;
        $server->is_ignored = (bool) ($validated['is_ignored'] ?? false);
        $server->ignore_reason = $validated['ignore_reason'] ?? null;

        if (! empty($validated['ssh_password'])) {
            $server->ssh_password = $validated['ssh_password'];
        }

        $server->save();

        $message = "Server '{$server->name}' created.";

        if (app(SpinupWpClient::class)->isConfigured()) {
            $import = $this->startImportInBackground(
                'fleet.import_spinupwp',
                ['clockwork:import-spinupwp', 'clockwork:poll-servers'],
                'spinupwp-import-bg',
            );

            if ($import['ok']) {
                $message .= ' '.$import['summary'];
            } else {
                $message .= ' (SpinupWP refresh skipped: '.$import['summary'].')';
            }
        } else {
            $poll = app(BackgroundArtisan::class)->start(
                'fleet.poll_servers',
                ['clockwork:poll-servers'],
                600,
                'poll-servers-bg',
            );
            if ($poll->started()) {
                $message .= ' Fleet poll started in the background.';
            }
        }

        return redirect()
            ->route('servers.show', $server)
            ->with('status', $message);
    }

    /**
     * Permanently remove a server from Clockwork's inventory. Cascades to
     * related sites (per the FK constraint), server metrics, blocked_ips,
     * server_tag pivot.
     *
     * Destructive — requires the operator to type the server name as
     * confirmation, both client-side (confirm dialog) and server-side
     * (the request must include the matching name).
     *
     * Use this when a server has been decommissioned at the infrastructure
     * level (DigitalOcean droplet destroyed, SpinupWP site deleted) and you
     * want Clockwork to stop tracking it. For temporary 'don't poll' status
     * use toggleIgnore instead.
     */
    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $validated = $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if ($validated['confirm_name'] !== $server->name) {
            return redirect()
                ->route('servers.show', $server)
                ->with('status', "Confirmation name didn't match — server NOT deleted.");
        }

        $name = $server->name;
        $relatedSites = $server->sites()->count();
        $relatedMetrics = $server->metrics()->count();
        $relatedBans = $server->blockedIps()->count();

        $server->delete();

        return redirect()
            ->route('dashboard')
            ->with('status', sprintf(
                'Removed %s from Clockwork. Cascade: %d sites, %d metrics, %d ban records.',
                $name, $relatedSites, $relatedMetrics, $relatedBans,
            ));
    }

    public function toggleIgnore(Request $request, Server $server): RedirectResponse
    {
        if ($server->is_ignored) {
            $server->is_ignored = false;
            $server->ignore_reason = null;
            $server->save();
            $message = "{$server->name} is no longer ignored.";
        } else {
            $server->is_ignored = true;
            $server->ignore_reason = $request->input('reason', 'Manually ignored');
            $server->save();
            $message = "{$server->name} is now ignored.";
        }

        return redirect($request->input('return_to', route('servers.show', $server)))
            ->with('status', $message);
    }

    public function toggleAutoBanLlar(Request $request, Server $server): RedirectResponse
    {
        $server->auto_ban_llar = ! $server->auto_ban_llar;
        $server->save();

        $msg = $server->auto_ban_llar
            ? "Auto-ban from LLAR enabled on {$server->name}. New lockouts will be banned automatically."
            : "Auto-ban from LLAR disabled on {$server->name}. New lockouts will go to the review queue.";

        return back()->with('status', $msg);
    }

    public function toggleAutoBanWordfence(Request $request, Server $server): RedirectResponse
    {
        $server->auto_ban_wordfence = ! $server->auto_ban_wordfence;
        $server->save();

        $msg = $server->auto_ban_wordfence
            ? "Auto-ban from Wordfence enabled on {$server->name}. New blocks will be banned automatically."
            : "Auto-ban from Wordfence disabled on {$server->name}. New blocks will go to the review queue.";

        return back()->with('status', $msg);
    }

    public function recheckHealth(
        Server $server,
        CloudProviderRegistry $registry,
        CpuStatusClassifier $classifier,
    ): JsonResponse {
        if (! $server->provider_id) {
            return response()->json(['ok' => false, 'error' => 'Server has no provider ID — cannot poll.'], 422);
        }

        $windowMinutes = (int) config('clockwork.monitoring.metrics_window_minutes', 15);
        $end = now()->getTimestamp();
        $start = $end - ($windowMinutes * 60);

        try {
            $cpuPct = $registry->resolve($server->provider)->metrics($server, $start, $end)['cpu_pct'];
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $status = $classifier->statusForCpu($cpuPct);
        $now = now();

        $server->status = $status;
        $server->last_polled_at = $now;
        if ($status === Server::STATUS_RED && $server->getOriginal('status') !== Server::STATUS_RED) {
            $server->last_alert_at = $now;
        }
        $server->save();

        return response()->json([
            'ok' => true,
            'status' => $status,
            'cpu_pct' => $cpuPct !== null ? round($cpuPct, 1) : null,
            'last_polled' => 'just now',
        ]);
    }
}
