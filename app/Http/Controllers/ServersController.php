<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\CloudProvider\CloudProviderRegistry;
use App\Services\Monitoring\CpuStatusClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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

        $result = $this->runSpinupWpImport();

        if ($result['ok']) {
            return back()->with('status', 'Refreshed from SpinupWP. '.$result['summary']);
        }

        return back()->with('status_error', 'SpinupWP refresh failed. '.$result['summary']);
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

        $result = $this->runGridPaneImport();

        if ($result['ok']) {
            return back()->with('status', 'Refreshed from GridPane. '.$result['summary']);
        }

        return back()->with('status_error', 'GridPane refresh failed. '.$result['summary']);
    }

    /**
     * Runs `clockwork:import-gridpane` then `clockwork:poll-servers`. See
     * runSpinupWpImport() below for why the poll step is chained on.
     *
     * @return array{ok: bool, summary: string}
     */
    protected function runGridPaneImport(): array
    {
        try {
            $importExit = Artisan::call('clockwork:import-gridpane');
            $importOutput = trim((string) Artisan::output());
        } catch (\Throwable $e) {
            return ['ok' => false, 'summary' => 'error: '.$e->getMessage()];
        }

        $importSummary = $this->extractSummaryLines($importOutput, ['Servers:', 'Sites:']);

        if ($importExit !== 0) {
            return ['ok' => false, 'summary' => $importSummary ?: 'no summary'];
        }

        $pollSummary = '';
        try {
            Artisan::call('clockwork:poll-servers');
            $pollOutput = trim((string) Artisan::output());
            $pollLine = $this->extractSummaryLines($pollOutput, ['Done.']);
            $pollSummary = $pollLine ? 'Polled: '.preg_replace('/^Done\.\s*/', '', $pollLine) : '';
        } catch (\Throwable $e) {
            $pollSummary = 'Poll skipped: '.$e->getMessage();
        }

        $summary = trim($importSummary.($pollSummary ? ' · '.$pollSummary : ''));

        return ['ok' => true, 'summary' => $summary ?: 'no summary'];
    }

    /**
     * Runs `clockwork:import-spinupwp` then `clockwork:poll-servers` so a
     * just-added server gets its status (green/yellow/red) immediately
     * instead of sitting at the bottom of the dashboard as "unknown" until
     * the next minute-cron tick.
     *
     * Import is the gating step — if it fails, poll is skipped. Poll
     * failure does not flip the overall result to error, since the user's
     * primary intent ("refresh server + site list from SpinupWP") still
     * succeeded.
     *
     * @return array{ok: bool, summary: string}
     */
    protected function runSpinupWpImport(): array
    {
        try {
            $importExit = Artisan::call('clockwork:import-spinupwp');
            $importOutput = trim((string) Artisan::output());
        } catch (\Throwable $e) {
            return ['ok' => false, 'summary' => 'error: '.$e->getMessage()];
        }

        $importSummary = $this->extractSummaryLines($importOutput, ['Servers:', 'Sites:']);

        if ($importExit !== 0) {
            return ['ok' => false, 'summary' => $importSummary ?: 'no summary'];
        }

        // Poll the fleet so any new servers (or servers whose status hasn't
        // been classified yet) flip out of "unknown" into the right bucket.
        $pollSummary = '';
        try {
            Artisan::call('clockwork:poll-servers');
            $pollOutput = trim((string) Artisan::output());
            $pollLine = $this->extractSummaryLines($pollOutput, ['Done.']);
            // Reformat "Done. green=X yellow=Y red=Z unknown=W errors=E" → "Polled: ..."
            $pollSummary = $pollLine ? 'Polled: '.preg_replace('/^Done\.\s*/', '', $pollLine) : '';
        } catch (\Throwable $e) {
            $pollSummary = 'Poll skipped: '.$e->getMessage();
        }

        $summary = trim($importSummary.($pollSummary ? ' · '.$pollSummary : ''));

        return ['ok' => true, 'summary' => $summary ?: 'no summary'];
    }

    /**
     * Pull lines starting with any of the given prefixes from artisan output
     * and join them with " · " for compact flash display.
     *
     * @param  list<string>  $prefixes
     */
    protected function extractSummaryLines(string $output, array $prefixes): string
    {
        $matches = array_values(array_filter(
            preg_split('/\R/', $output) ?: [],
            function ($l) use ($prefixes) {
                $trimmed = trim($l);
                foreach ($prefixes as $p) {
                    if (str_starts_with($trimmed, $p)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        return implode(' · ', array_map('trim', $matches));
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
            // Auto-refresh from SpinupWP so any sites that already belong to
            // this server (or have moved between servers since the last
            // sync) appear immediately. Idempotent and harmless for
            // hand-rolled servers that have no SpinupWP record — they
            // simply get no update from this pass.
            $import = $this->runSpinupWpImport();

            if ($import['ok']) {
                $message .= ' Refreshed from SpinupWP — '.$import['summary'].'.';
            } else {
                $message .= ' (SpinupWP refresh skipped: '.$import['summary'].')';
            }
        } else {
            // No SpinupWP account on this fleet — skip the import entirely
            // rather than run a command that will just fail, but still poll
            // so this new server's status (green/yellow/red) classifies
            // immediately instead of sitting at "unknown" until the next
            // scheduled tick.
            try {
                Artisan::call('clockwork:poll-servers');
            } catch (\Throwable) {
                // Best-effort — the server was created either way.
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
